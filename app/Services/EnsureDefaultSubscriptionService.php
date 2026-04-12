<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\Status;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

final class EnsureDefaultSubscriptionService
{
    /**
     * ensure free subscription if no active subscription exists at all
     * Uses a transaction + row lock to reduce duplicate free rows under concurrent calls.
     */
    public function ensureFreeSubscription(AppUser $user): void
    {
        DB::transaction(function () use ($user) {
            AppUser::whereKey($user->id)->lockForUpdate()->first();

            $hasActive = UserSubscription::query()
                ->where('app_user_id', $user->id)
                ->whereHas('status', fn ($q) => $q->whereIn('name', ['active', 'trialing']))
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
                'current_period_end' => null,
                'cancel_at_period_end' => false,
            ]);
        });
    }
}
