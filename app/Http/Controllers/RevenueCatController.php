<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\Status;
use App\Models\UserSubscription;
use App\Services\DiscordNotifier;
use App\Services\EnsureDefaultSubscriptionService;
use App\Services\ErrorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles RevenueCat webhooks (iOS in-app purchases). Mirrors StripeController's
 * webhook/event-dispatch structure — see that class for the equivalent Stripe flow.
 *
 * Auth: RevenueCat resends whatever "Authorization header value" is configured in its
 * dashboard (Integrations → Webhooks) verbatim on every request — a static shared secret,
 * not a signed payload like Stripe's.
 */
class RevenueCatController extends Controller
{
    private ?array $statusCache = null;

    public function __construct(
        private EnsureDefaultSubscriptionService $ensureDefaultSubscription,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.revenuecat.webhook_secret');
        $provided = (string) $request->header('Authorization');

        if (empty($secret) || ! hash_equals((string) $secret, $provided)) {
            Log::warning('RevenueCat webhook: invalid or missing Authorization header');

            return response()->json(['message' => 'Invalid authorization'], 401);
        }

        $event = $request->input('event', []);
        $type = $event['type'] ?? null;

        if (! is_array($event) || ! $type) {
            Log::warning('RevenueCat webhook: malformed payload', ['body' => $request->all()]);

            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $this->handleEvent($type, $event);
        } catch (\Throwable $e) {
            Log::error('RevenueCat webhook handler failed', [
                'event_id' => $event['id'] ?? null,
                'event_type' => $type,
                'error' => $e->getMessage(),
            ]);
            ErrorNotifier::notify(
                'RevenueCat webhook handler failed',
                $e->getMessage(),
                ['event_id' => $event['id'] ?? null, 'event_type' => $type],
            );

            return response()->json(['message' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    // ──────────────────────────────────────────────
    // Event dispatcher
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function handleEvent(string $type, array $event): void
    {
        switch ($type) {
            case 'TEST':
                Log::info('RevenueCat webhook: TEST event received');
                break;
            case 'INITIAL_PURCHASE':
            case 'RENEWAL':
            case 'UNCANCELLATION':
            case 'PRODUCT_CHANGE':
                $this->onActivePurchaseEvent($event, $type);
                break;
            case 'CANCELLATION':
                $this->onCancellation($event);
                break;
            case 'EXPIRATION':
                $this->onExpiration($event);
                break;
            case 'BILLING_ISSUE':
                $this->onBillingIssue($event);
                break;
            default:
                // SUBSCRIPTION_PAUSED (Play Store only), TRANSFER, etc. — not reachable with
                // our iOS-only, always-identified (never anonymous) RevenueCat setup.
                Log::info('RevenueCat webhook: unhandled event type', ['type' => $type]);
        }
    }

    // ──────────────────────────────────────────────
    // INITIAL_PURCHASE / RENEWAL / UNCANCELLATION / PRODUCT_CHANGE
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function onActivePurchaseEvent(array $event, string $type): void
    {
        $user = $this->resolveUser($event, $type);
        if (! $user) {
            return;
        }

        $plan = $this->resolvePlanByProductId((string) ($event['product_id'] ?? ''));
        if (! $plan) {
            $context = [
                'app_user_id' => $user->id,
                'product_id' => $event['product_id'] ?? null,
                'event_id' => $event['id'] ?? null,
                'event_type' => $type,
            ];
            Log::error('RevenueCat: could not resolve plan for product_id', $context);
            ErrorNotifier::notify(
                'RevenueCat: unknown product_id',
                "No local Plan matches product_id {$event['product_id']}",
                $context,
            );

            return;
        }

        $activeId = $this->requireStatusId('active', [
            'app_user_id' => $user->id,
            'event_id' => $event['id'] ?? null,
            'handler' => $type,
        ]);
        if ($activeId === null) {
            return;
        }

        $originalTransactionId = (string) ($event['original_transaction_id'] ?? '');
        $wasNew = ! UserSubscription::where('payment_subscription_id', $originalTransactionId)->exists();

        UserSubscription::updateOrCreate(
            ['payment_subscription_id' => $originalTransactionId],
            [
                'app_user_id' => $user->id,
                'plan_id' => $plan->id,
                'status_id' => $activeId,
                'current_period_end' => $this->carbonFromMs($event['expiration_at_ms'] ?? null),
                'cancel_at_period_end' => false,
                'used_checks_count' => 0,
                'scans_reset_at' => now()->addDays(30),
            ],
        );

        if ($wasNew) {
            $this->cancelFreeSubscription($user);
            DiscordNotifier::payment(
                'Subscription started',
                sprintf('User `%d` — **%s** (`%s`) via RevenueCat', $user->id, $plan->label, $plan->type->value),
                ['original_transaction_id' => $originalTransactionId, 'event_type' => $type],
            );
        }
    }

    // ──────────────────────────────────────────────
    // CANCELLATION — auto-renew turned off, access continues until expiration
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function onCancellation(array $event): void
    {
        $originalTransactionId = (string) ($event['original_transaction_id'] ?? '');
        $local = UserSubscription::where('payment_subscription_id', $originalTransactionId)->first();

        if (! $local) {
            Log::warning('RevenueCat: CANCELLATION — no local user_subscriptions row', [
                'original_transaction_id' => $originalTransactionId,
                'event_id' => $event['id'] ?? null,
            ]);

            return;
        }

        $canceledId = $this->requireStatusId('canceled', [
            'original_transaction_id' => $originalTransactionId,
            'event_id' => $event['id'] ?? null,
            'handler' => 'CANCELLATION',
        ]);
        if ($canceledId === null) {
            return;
        }

        // Preserve access until period end — mirrors StripeController::onSubscriptionDeleted
        // for the "cancel_at_period_end" case. EXPIRATION will revoke access for real later.
        $local->update([
            'status_id' => $canceledId,
            'current_period_end' => $this->carbonFromMs($event['expiration_at_ms'] ?? null) ?? $local->current_period_end,
            'cancel_at_period_end' => true,
        ]);

        $local->load('plan');
        DiscordNotifier::payment(
            'Subscription canceled (auto-renew off)',
            sprintf('User `%d` — was **%s**, access continues until period end', $local->app_user_id, $local->plan->label ?? '?'),
            ['original_transaction_id' => $originalTransactionId],
        );
    }

    // ──────────────────────────────────────────────
    // EXPIRATION — subscription actually ended, revoke access now
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function onExpiration(array $event): void
    {
        $originalTransactionId = (string) ($event['original_transaction_id'] ?? '');
        $local = UserSubscription::where('payment_subscription_id', $originalTransactionId)->first();

        if (! $local) {
            Log::warning('RevenueCat: EXPIRATION — no local user_subscriptions row', [
                'original_transaction_id' => $originalTransactionId,
                'event_id' => $event['id'] ?? null,
            ]);

            return;
        }

        $canceledId = $this->requireStatusId('canceled', [
            'original_transaction_id' => $originalTransactionId,
            'event_id' => $event['id'] ?? null,
            'handler' => 'EXPIRATION',
        ]);
        if ($canceledId === null) {
            return;
        }

        $local->update(['status_id' => $canceledId]);

        $user = $local->appUser;
        if ($user) {
            $this->ensureDefaultSubscription->ensureFreeSubscription($user);

            $local->load('plan');
            DiscordNotifier::payment(
                'Subscription expired',
                sprintf('User `%d` — was **%s**', $user->id, $local->plan->label ?? '?'),
                ['original_transaction_id' => $originalTransactionId],
            );
        }
    }

    // ──────────────────────────────────────────────
    // BILLING_ISSUE — Apple retrying a failed renewal payment
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function onBillingIssue(array $event): void
    {
        $originalTransactionId = (string) ($event['original_transaction_id'] ?? '');
        $local = UserSubscription::where('payment_subscription_id', $originalTransactionId)->first();

        if (! $local) {
            Log::warning('RevenueCat: BILLING_ISSUE — no local user_subscriptions row', [
                'original_transaction_id' => $originalTransactionId,
                'event_id' => $event['id'] ?? null,
            ]);

            return;
        }

        $pastDueId = $this->requireStatusId('past_due', [
            'original_transaction_id' => $originalTransactionId,
            'event_id' => $event['id'] ?? null,
            'handler' => 'BILLING_ISSUE',
        ]);
        if ($pastDueId === null) {
            return;
        }

        $local->update(['status_id' => $pastDueId]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $event */
    private function resolveUser(array $event, string $eventType): ?AppUser
    {
        $appUserId = (int) ($event['app_user_id'] ?? 0);
        $user = $appUserId > 0 ? AppUser::find($appUserId) : null;

        if ($user) {
            return $user;
        }

        $context = [
            'app_user_id' => $event['app_user_id'] ?? null,
            'event_id' => $event['id'] ?? null,
            'event_type' => $eventType,
        ];
        Log::error("RevenueCat: {$eventType} — no AppUser found", $context);
        ErrorNotifier::notify(
            "RevenueCat: {$eventType} — unknown app_user_id",
            "No AppUser with id = {$event['app_user_id']}",
            $context,
        );

        return null;
    }

    private function resolvePlanByProductId(string $productId): ?Plan
    {
        $planType = config("services.revenuecat.products.{$productId}");
        if (! $planType) {
            Log::warning('RevenueCat: could not resolve plan for product_id', ['product_id' => $productId]);

            return null;
        }

        return Plan::where('type', $planType)->first();
    }

    private function carbonFromMs(mixed $ms): ?Carbon
    {
        if ($ms === null || ! (is_int($ms) || is_float($ms) || is_string($ms))) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $ms);
    }

    /** Cached per-request map of statuses.name => id. */
    private function resolveStatusId(string $name): ?int
    {
        if ($this->statusCache === null) {
            $this->statusCache = Status::pluck('id', 'name')->toArray();
        }

        return $this->statusCache[$name] ?? null;
    }

    /** @param  array<string, mixed>  $logContext */
    private function requireStatusId(string $name, array $logContext): ?int
    {
        $id = $this->resolveStatusId($name);
        if ($id !== null) {
            return $id;
        }

        Log::error('RevenueCat: missing local status row', array_merge($logContext, ['expected_status_name' => $name]));
        ErrorNotifier::notify(
            'RevenueCat: missing status mapping',
            "No statuses row for name '{$name}'",
            array_merge($logContext, ['expected_status_name' => $name]),
        );

        return null;
    }

    /** Cancel any active free-plan subscription for the user (one active subscription rule). */
    private function cancelFreeSubscription(AppUser $user): void
    {
        $canceledId = $this->requireStatusId('canceled', [
            'app_user_id' => $user->id,
            'handler' => 'cancelFreeSubscription',
        ]);
        if ($canceledId === null) {
            return;
        }

        $user->userSubscriptions()
            ->whereNull('payment_subscription_id')
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['active', 'trialing']))
            ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Free->value))
            ->update(['status_id' => $canceledId]);
    }
}
