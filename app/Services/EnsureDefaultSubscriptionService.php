<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\Status;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

/** Not final: tests mock this out to simulate an unexpected failure in webhook handling. */
class EnsureDefaultSubscriptionService
{
    /**
     * ensure free subscription if no active subscription exists at all
     * Uses a transaction + row lock to reduce duplicate free rows under concurrent calls.
     */
    public function ensureFreeSubscription(AppUser $user): void
    {
        DB::transaction(function () use ($user) {
            AppUser::whereKey($user->id)->lockForUpdate()->first();

            // Also treat canceled subscriptions still within their paid period, and past_due
            // subscriptions within their grace period, as "active" to avoid creating a
            // premature free plan while the user still has access. Mirrors AppUser::activeSubscription().
            $hasActive = UserSubscription::query()
                ->where('app_user_id', $user->id)
                ->where(fn ($q) => $q
                    ->whereHas('status', fn ($sq) => $sq->whereIn('name', ['active', 'trialing']))
                    ->orWhere(fn ($sq) => $sq
                        ->whereHas('status', fn ($ssq) => $ssq->where('name', 'canceled'))
                        ->where('current_period_end', '>', now())
                    )
                    ->orWhere(fn ($sq) => $sq
                        ->whereHas('status', fn ($ssq) => $ssq->where('name', 'past_due'))
                        ->where('current_period_end', '>', now()->subDays(AppUser::PAST_DUE_GRACE_DAYS))
                    )
                )
                ->exists();

            if ($hasActive) {
                return;
            }

            $existingFreeActive = UserSubscription::query()
                ->where('app_user_id', $user->id)
                ->whereNull('payment_subscription_id')
                ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Free->value))
                ->whereHas('status', fn ($q) => $q->where('name', 'active'))
                ->lockForUpdate()
                ->exists();

            if ($existingFreeActive) {
                return;
            }

            $freePlan = Plan::where('type', PlanType::Free->value)->first();
            $activeStatus = Status::where('name', 'active')->first();

            if (! $freePlan || ! $activeStatus) {
                return;
            }

            UserSubscription::create([
                'app_user_id' => $user->id,
                'plan_id' => $freePlan->id,
                'status_id' => $activeStatus->id,
                'payment_subscription_id' => null,
                'used_checks_count' => 0,
                'scans_reset_at' => now()->addDays(30),
                'current_period_end' => null,
                'cancel_at_period_end' => false,
            ]);
        });
    }
}
