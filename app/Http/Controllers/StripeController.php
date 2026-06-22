<?php

namespace App\Http\Controllers;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Status;
use App\Models\StripeCheckoutLog;
use App\Models\UserSubscription;
use App\Services\DiscordNotifier;
use App\Services\EnsureDefaultSubscriptionService;
use App\Services\ErrorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

class StripeController extends Controller
{
    private ?array $statusCache = null;

    public function __construct(
        private EnsureDefaultSubscriptionService $ensureDefaultSubscription,
    ) {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ──────────────────────────────────────────────
    // Checkout
    // ──────────────────────────────────────────────

    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'plan_type' => ['required', 'string', Rule::in(PlanType::paidValues())],
        ]);

        $user = $request->user();
        $planType = $request->input('plan_type');

        if ($this->userHasActivePaidSubscription($user)) {
            return response()->json([
                'message' => 'You already have an active subscription. Use POST /api/stripe/portal to change or manage your plan.',
            ], 409);
        }

        $currency = strtolower($user->settings?->currency ?? 'usd'); // @phpstan-ignore nullsafe.neverNull
        $priceId = config("services.stripe.prices.{$planType}.{$currency}")
            ?? config("services.stripe.prices.{$planType}.usd");

        if (! $priceId) {
            return response()->json(['message' => 'No Stripe price configured for this plan.'], 422);
        }

        try {
            $customerId = $this->getOrCreateStripeCustomer($user);

            $session = CheckoutSession::create([
                'mode' => 'subscription',
                'customer' => $customerId,
                'client_reference_id' => (string) $user->id,
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'allow_promotion_codes' => true,
                'success_url' => config('services.stripe.success_url'),
                'cancel_url' => config('services.stripe.cancel_url'),
                'metadata' => [
                    'app_user_id' => (string) $user->id,
                    'plan_type' => (string) $planType,
                    'currency' => $currency,
                ],
            ]);

            return response()->json(['checkout_url' => $session->url]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session failed', [
                'app_user_id' => $user->id,
                'plan_type' => $planType,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to create checkout session.',
            ], 500);
        }
    }

    // ──────────────────────────────────────────────
    // Portal
    // ──────────────────────────────────────────────

    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->payment_customer_id) {
            return response()->json([
                'message' => 'No billing account found. Please subscribe to a plan first.',
            ], 400);
        }

        try {
            $session = PortalSession::create([
                'customer' => $user->payment_customer_id,
                'return_url' => config('services.stripe.success_url'),
            ]);

            return response()->json(['portal_url' => $session->url]);
        } catch (\Throwable $e) {
            Log::error('Stripe portal session failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Unable to create billing portal session.',
            ], 500);
        }
    }

    // ──────────────────────────────────────────────
    // Webhook
    // ──────────────────────────────────────────────

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::warning('Stripe webhook payload invalid', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $this->handleEvent($event);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed', [
                'event_id' => $event->id ?? null,
                'event_type' => $event->type ?? null,
                'error' => $e->getMessage(),
            ]);
            ErrorNotifier::notify(
                'Stripe webhook handler failed',
                $e->getMessage(),
                [
                    'event_id' => $event->id ?? null,
                    'event_type' => $event->type ?? null,
                ],
            );

            return response()->json(['message' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    // ──────────────────────────────────────────────
    // Event dispatcher
    // ──────────────────────────────────────────────

    private function handleEvent(Event $event): void
    {
        $object = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
                if ($object instanceof Session) {
                    $this->onCheckoutSessionCompleted($object);
                }
                break;
            case 'customer.subscription.created':
                if ($object instanceof Subscription) {
                    $this->onSubscriptionCreated($object, $event->id);
                }
                break;
            case 'customer.subscription.updated':
                if ($object instanceof Subscription) {
                    $this->onSubscriptionUpdated($object, $event->id);
                }
                break;
            case 'customer.subscription.deleted':
                if ($object instanceof Subscription) {
                    $this->onSubscriptionDeleted($object, $event->id);
                }
                break;
            case 'invoice.paid':
                if ($object instanceof Invoice) {
                    $this->onInvoicePaid($object);
                }
                break;
            case 'invoice.payment_failed':
                if ($object instanceof Invoice) {
                    $this->onInvoicePaymentFailed($object);
                }
                break;
        }
    }

    // ──────────────────────────────────────────────
    // checkout.session.completed — traceability only
    // ──────────────────────────────────────────────

    private function onCheckoutSessionCompleted(Session $session): void
    {
        $context = [
            'stripe_checkout_session_id' => $session->id,
            'stripe_customer_id' => $session->customer,
            'app_user_id' => $session->metadata->app_user_id ?? $session->client_reference_id,
            'plan_type' => $session->metadata->plan_type ?? null,
            'currency' => $session->metadata->currency ?? null,
        ];

        Log::info('Stripe checkout.session.completed', $context);

        $appUserId = isset($session->metadata->app_user_id)
            ? (int) $session->metadata->app_user_id
            : (int) ($session->client_reference_id ?? 0);
        if ($appUserId === 0) {
            $appUserId = null;
        }

        $stripeCustomerId = is_string($session->customer)
            ? $session->customer
            : $session->customer?->id;

        // Best-effort only: losing this row should not fail the webhook (subscription/invoice events are separate deliveries).
        try {
            StripeCheckoutLog::updateOrCreate(
                ['stripe_checkout_session_id' => $session->id],
                [
                    'stripe_customer_id' => $stripeCustomerId,
                    'app_user_id' => $appUserId,
                    'plan_type' => $session->metadata->plan_type ?? null,
                    'currency' => $session->metadata->currency ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe: checkout log persist failed (non-fatal)', [
                'stripe_checkout_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        $user = $stripeCustomerId
            ? AppUser::where('payment_customer_id', $stripeCustomerId)->first()
            : null;
        if (! $user && $stripeCustomerId) {
            ErrorNotifier::notify(
                'Stripe: checkout.session.completed — unknown customer',
                "No AppUser found for Stripe customer {$stripeCustomerId}",
                $context,
            );
        }
    }

    // ──────────────────────────────────────────────
    // customer.subscription.created
    // ──────────────────────────────────────────────

    private function onSubscriptionCreated(Subscription $subscription, string $eventId): void
    {
        $user = $this->resolveUserFromSubscription($subscription, $eventId, 'customer.subscription.created');
        if (! $user) {
            return;
        }

        $priceId = $subscription->items->data[0]->price->id ?? null;
        if (! $priceId) {
            ErrorNotifier::notify(
                'Stripe: subscription.created — no price ID',
                "No price ID found for subscription {$subscription->id}",
                [
                    'stripe_customer_id' => $subscription->customer,
                    'payment_subscription_id' => $subscription->id,
                    'event_id' => $eventId,
                ],
            );

            return;
        }

        $plan = $this->resolvePlanByPriceId($priceId);

        if (! $plan) {
            $context = [
                'stripe_customer_id' => $subscription->customer,
                'payment_subscription_id' => $subscription->id,
                'price_id' => $priceId,
                'event_id' => $eventId,
                'event_type' => 'customer.subscription.created',
            ];
            Log::error('Stripe: subscription.created — could not resolve plan for price', $context);
            ErrorNotifier::notify(
                'Stripe: subscription.created — unknown price',
                "No local Plan matches Stripe price_id {$priceId}",
                $context,
            );

            return;
        }

        $statusId = $this->requireStatusId($subscription->status, [
            'stripe_customer_id' => $subscription->customer,
            'payment_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'handler' => 'customer.subscription.created',
        ]);
        if ($statusId === null) {
            return;
        }

        UserSubscription::updateOrCreate(
            ['payment_subscription_id' => $subscription->id],
            [
                'app_user_id' => $user->id,
                'plan_id' => $plan->id,
                'status_id' => $statusId,
                'current_period_end' => $this->resolveSubscriptionCurrentPeriodEnd($subscription, $eventId, 'customer.subscription.created'),
                'cancel_at_period_end' => $this->stripeBool($subscription->cancel_at_period_end),
                'used_checks_count' => 0,
                'scans_reset_at' => now()->addDays(30),
                'trial_start' => $this->carbonFromStripeTimestamp($subscription->trial_start),
                'trial_end' => $this->carbonFromStripeTimestamp($subscription->trial_end),
            ],
        );

        $this->cancelFreeSubscription($user);

        DiscordNotifier::payment(
            'Subscription started',
            sprintf('User `%d` — **%s** (`%s`)', $user->id, $plan->label, $plan->type->value),
            [
                'payment_subscription_id' => $subscription->id,
                'stripe_customer_id' => is_string($subscription->customer) ? $subscription->customer : $subscription->customer->id,
            ],
        );
    }

    // ──────────────────────────────────────────────
    // customer.subscription.updated
    // ──────────────────────────────────────────────

    private function onSubscriptionUpdated(Subscription $subscription, string $eventId): void
    {
        $local = UserSubscription::where('payment_subscription_id', $subscription->id)->first();

        if (! $local) {
            $this->onSubscriptionCreated($subscription, $eventId);

            return;
        }

        $statusId = $this->requireStatusId($subscription->status, [
            'stripe_customer_id' => $subscription->customer,
            'payment_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'handler' => 'customer.subscription.updated',
        ]);
        if ($statusId === null) {
            return;
        }

        $updates = [
            'status_id' => $statusId,
            'current_period_end' => $this->resolveSubscriptionCurrentPeriodEnd($subscription, $eventId, 'customer.subscription.updated'),
            'cancel_at_period_end' => $this->stripeBool($subscription->cancel_at_period_end),
        ];

        $newPriceId = $subscription->items->data[0]->price->id ?? null;
        if ($newPriceId) {
            $newPlan = $this->resolvePlanByPriceId($newPriceId);
            if ($newPlan && $newPlan->id !== $local->plan_id) {
                $updates['plan_id'] = $newPlan->id;
            } elseif (! $newPlan) {
                $context = [
                    'stripe_customer_id' => $subscription->customer,
                    'payment_subscription_id' => $subscription->id,
                    'price_id' => $newPriceId,
                    'event_id' => $eventId,
                    'event_type' => 'customer.subscription.updated',
                ];
                Log::error('Stripe: subscription.updated — could not resolve plan for price', $context);
                ErrorNotifier::notify(
                    'Stripe: subscription.updated — unknown price',
                    "No local Plan matches Stripe price_id {$newPriceId}",
                    $context,
                );
            }
        }

        $local->update($updates);
    }

    // ──────────────────────────────────────────────
    // customer.subscription.deleted
    // ──────────────────────────────────────────────

    private function onSubscriptionDeleted(Subscription $subscription, string $eventId): void
    {
        $local = UserSubscription::where('payment_subscription_id', $subscription->id)->first();

        if (! $local) {
            Log::warning('Stripe: subscription.deleted — no local record', [
                'payment_subscription_id' => $subscription->id,
                'event_id' => $eventId,
            ]);

            return;
        }

        $canceledId = $this->requireStatusId('canceled', [
            'payment_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'handler' => 'customer.subscription.deleted',
        ]);
        if ($canceledId === null) {
            return;
        }

        $local->load('plan');
        $planLabel = $local->plan->label ?? $local->plan->type->value ?? '?';

        $periodEnd = $this->resolveSubscriptionCurrentPeriodEnd($subscription, $eventId, 'customer.subscription.deleted');

        if ($periodEnd?->isFuture()) {
            // Immediate cancellation — billing period hasn't ended yet.
            // Preserve access until period end; middleware will provision free plan on next request.
            $local->update([
                'status_id' => $canceledId,
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => true,
            ]);
        } else {
            // Period already ended (cancel_at_period_end portal flow) or timestamp missing.
            // Revoke paid access immediately and provision free plan.
            $local->update(['status_id' => $canceledId]);
        }

        $user = $local->appUser;
        if ($user) {
            if ($periodEnd?->isFuture()) {
                Log::info('Stripe: subscription.deleted — access deferred to period end', [
                    'app_user_id' => $user->id,
                    'current_period_end' => $periodEnd,
                    'payment_subscription_id' => $subscription->id,
                    'event_id' => $eventId,
                ]);
            } else {
                $this->ensureDefaultSubscription->ensureFreeSubscription($user);
                Log::info('Stripe: subscription.deleted — free plan created immediately', [
                    'app_user_id' => $user->id,
                    'current_period_end' => $periodEnd,
                    'payment_subscription_id' => $subscription->id,
                    'event_id' => $eventId,
                ]);
            }

            DiscordNotifier::payment(
                'Subscription canceled',
                sprintf('User `%d` — was **%s**', $user->id, $planLabel),
                ['payment_subscription_id' => $subscription->id],
            );
        }
    }

    // ──────────────────────────────────────────────
    // invoice.paid — payment record + status recovery
    // ──────────────────────────────────────────────

    private function onInvoicePaid(Invoice $invoice): void
    {
        $subscriptionId = $this->stripeInvoiceSubscriptionId($invoice);
        if ($subscriptionId === null) {
            return;
        }

        $local = UserSubscription::where('payment_subscription_id', $subscriptionId)->first();
        $appUserId = $local?->app_user_id;

        if (! $appUserId) {
            $user = AppUser::where('payment_customer_id', $invoice->customer)->first();
            $appUserId = $user?->id;
        }

        if ($appUserId) {
            $activeStatusId = $this->requireStatusId('active', [
                'invoice_id' => $invoice->id,
                'handler' => 'invoice.paid',
            ]);
            if ($activeStatusId !== null) {
                Payment::updateOrCreate(
                    ['payment_invoice_id' => $invoice->id],
                    [
                        'app_user_id' => $appUserId,
                        'amount' => $invoice->amount_paid,
                        'currency' => $invoice->currency,
                        'status_id' => $activeStatusId,
                    ],
                );
            }
        }

        if (! $local) {
            $context = [
                'invoice_id' => $invoice->id,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => $invoice->customer,
            ];
            Log::warning('Stripe: invoice.paid — no local user_subscriptions row for subscription', $context);
            ErrorNotifier::notify(
                'Stripe: invoice.paid — subscription not in DB',
                "No UserSubscription with payment_subscription_id = {$subscriptionId}",
                $context,
            );

            return;
        }

        $statusName = $local->status?->name;
        if (in_array($statusName, ['past_due', 'pending'], true)) {
            $activeId = $this->requireStatusId('active', [
                'invoice_id' => $invoice->id,
                'payment_subscription_id' => $subscriptionId,
                'handler' => 'invoice.paid_recovery',
            ]);
            if ($activeId !== null) {
                $local->update(['status_id' => $activeId]);
            }
        }

        if ($invoice->billing_reason === 'subscription_cycle') {
            $local->update([
                'used_checks_count' => 0,
                'scans_reset_at' => now()->addDays(30),
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // invoice.payment_failed
    // ──────────────────────────────────────────────

    private function onInvoicePaymentFailed(Invoice $invoice): void
    {
        $subscriptionId = $this->stripeInvoiceSubscriptionId($invoice);
        if ($subscriptionId === null) {
            return;
        }

        $local = UserSubscription::where('payment_subscription_id', $subscriptionId)->first();

        if (! $local) {
            Log::warning('Stripe: invoice.payment_failed — no local user_subscriptions row for subscription', [
                'invoice_id' => $invoice->id,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => $invoice->customer,
            ]);

            return;
        }

        $pastDueId = $this->requireStatusId('past_due', [
            'invoice_id' => $invoice->id,
            'payment_subscription_id' => $subscriptionId,
            'handler' => 'invoice.payment_failed',
        ]);
        if ($pastDueId === null) {
            return;
        }

        $local->update(['status_id' => $pastDueId]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /** Stripe JSON sometimes encodes booleans as 0/1; PostgreSQL expects real booleans. */
    private function stripeBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Stripe Subscription timestamps can be null on some events/API shapes; never pass null into Carbon. */
    private function carbonFromStripeTimestamp(mixed $timestamp): ?Carbon
    {
        if ($timestamp === null || ! (is_int($timestamp) || is_float($timestamp) || is_string($timestamp))) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }

    /**
     * End of current billing period from Stripe. With `billing_mode.type = flexible`, the root
     * Subscription object may omit `current_period_end`; it appears on `items.data[0].current_period_end`
     * (see docs/stripe-webhook-samples/customer.subscription.created.json).
     */
    private function resolveSubscriptionCurrentPeriodEnd(StripeSubscription $subscription, string $eventId, string $handler): ?Carbon
    {
        $fromRoot = $this->carbonFromStripeTimestamp($subscription['current_period_end']);
        if ($fromRoot !== null) {
            return $fromRoot;
        }

        $items = $subscription->items->data ?? [];
        if ($items !== [] && isset($items[0]['current_period_end'])) {
            $fromItem = $this->carbonFromStripeTimestamp($items[0]['current_period_end']);
            if ($fromItem !== null) {
                return $fromItem;
            }
        }

        try {
            $fresh = StripeSubscription::retrieve($subscription->id);
            $fromApi = $this->carbonFromStripeTimestamp($fresh['current_period_end']);
            if ($fromApi !== null) {
                return $fromApi;
            }
            $freshItems = $fresh->items->data ?? [];
            if ($freshItems !== [] && isset($freshItems[0]['current_period_end'])) {
                $fromFreshItem = $this->carbonFromStripeTimestamp($freshItems[0]['current_period_end']);
                if ($fromFreshItem !== null) {
                    return $fromFreshItem;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Stripe: Subscription::retrieve failed while resolving current_period_end', [
                'payment_subscription_id' => $subscription->id,
                'event_id' => $eventId,
                'handler' => $handler,
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('Stripe: current_period_end missing on webhook and after Subscription retrieve', [
            'payment_subscription_id' => $subscription->id,
            'stripe_status' => $subscription->status,
            'event_id' => $eventId,
            'handler' => $handler,
        ]);

        return null;
    }

    /**
     * Subscription id on invoices: classic API exposes `subscription`; newer payloads nest it under
     * `parent.subscription_details.subscription` (see docs/stripe-webhook-samples/invoice.paid.json).
     */
    private function stripeInvoiceSubscriptionId(Invoice $invoice): ?string
    {
        $invoiceSubscription = $invoice['subscription'];
        if ($invoiceSubscription) {
            return is_string($invoiceSubscription) ? $invoiceSubscription : $invoiceSubscription->id;
        }

        $parent = $invoice->parent ?? null;
        if ($parent !== null) {
            $details = $parent->subscription_details ?? null;
            if ($details !== null && ! empty($details->subscription)) {
                $sid = $details->subscription;

                return is_string($sid) ? $sid : $sid->id;
            }
        }

        foreach ($invoice->lines->data ?? [] as $line) {
            $lineParent = $line->parent ?? null;
            if ($lineParent === null || ($lineParent->type ?? '') !== 'subscription_item_details') {
                continue;
            }
            $sid = $lineParent->subscription_item_details->subscription ?? null;
            if (is_string($sid)) {
                return $sid;
            }
        }

        return null;
    }

    /** Find the AppUser by Stripe customer ID. Logs + notifies if not found instead of throwing. */
    private function resolveUserFromSubscription(Subscription $subscription, string $eventId, string $eventType): ?AppUser
    {
        $user = AppUser::where('payment_customer_id', $subscription->customer)->first();

        if ($user) {
            return $user;
        }

        $context = [
            'stripe_customer_id' => $subscription->customer,
            'payment_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'event_type' => $eventType,
        ];

        Log::error("Stripe: {$eventType} — no AppUser found for customer", $context);
        ErrorNotifier::notify(
            "Stripe: {$eventType} — unknown customer",
            "No AppUser with payment_customer_id = {$subscription->customer}",
            $context,
        );

        return null;
    }

    /**
     * Map Stripe subscription status to local `statuses.name` before lookup.
     */
    private function mappedLocalStatusName(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'incomplete', 'incomplete_expired' => 'pending',
            default => $stripeStatus,
        };
    }

    /** Map Stripe status string to local statuses.id. Caches the full mapping per request. */
    private function resolveStatusId(string $stripeStatus): ?int
    {
        if ($this->statusCache === null) {
            $this->statusCache = Status::pluck('id', 'name')->toArray();
        }

        $name = $this->mappedLocalStatusName($stripeStatus);

        return $this->statusCache[$name] ?? null;
    }

    /**
     * Like resolveStatusId but logs + notifies when the row is missing (avoids writing null status_id).
     *
     * @param  array<string, mixed>  $logContext
     */
    private function requireStatusId(string $stripeStatus, array $logContext): ?int
    {
        $id = $this->resolveStatusId($stripeStatus);
        if ($id !== null) {
            return $id;
        }

        $expectedName = $this->mappedLocalStatusName($stripeStatus);
        Log::error('Stripe: missing local status row for Stripe status', array_merge($logContext, [
            'stripe_status' => $stripeStatus,
            'expected_statuses_name' => $expectedName,
        ]));
        ErrorNotifier::notify(
            'Stripe: missing status mapping',
            "No statuses row for name '{$expectedName}' (Stripe status: {$stripeStatus})",
            array_merge($logContext, ['stripe_status' => $stripeStatus, 'expected_statuses_name' => $expectedName]),
        );

        return null;
    }

    /**
     * Resolve a local Plan by Stripe Price ID using config mapping.
     * MVP approach — long-term consider migrating to DB-based plan_prices mapping.
     */
    private function resolvePlanByPriceId(string $priceId): ?Plan
    {
        $prices = config('services.stripe.prices', []);

        foreach ($prices as $planType => $currencies) {
            foreach ($currencies as $currency => $configPriceId) {
                if ($configPriceId === $priceId) {
                    return Plan::where('type', $planType)->first();
                }
            }
        }

        Log::warning('Stripe: could not resolve plan for price', ['price_id' => $priceId]);

        return null;
    }

    /** True if user has an active Stripe-backed subscription (not free-only). */
    private function userHasActivePaidSubscription(AppUser $user): bool
    {
        return $user->userSubscriptions()
            ->whereNotNull('payment_subscription_id')
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['active', 'trialing']))
            ->exists();
    }

    /** Get existing Stripe Customer ID or create a new one and persist it. */
    private function getOrCreateStripeCustomer(AppUser $user): string
    {
        if ($user->payment_customer_id) {
            return $user->payment_customer_id;
        }

        $customer = Customer::create([
            'email' => $user->email,
            'metadata' => ['app_user_id' => (string) $user->id],
        ]);

        $user->update(['payment_customer_id' => $customer->id]);

        return $customer->id;
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
