<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanType;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

// [SOLID:S] Only responsible for checking and applying quota reset for free plans
final class ScanQuotaResetService
{
    public function ensureReset(UserSubscription $subscription): void
    {
        if (! $subscription->relationLoaded('plan')) {
            $subscription->load('plan');
        }

        if (! $this->needsReset($subscription)) {
            return;
        }

        DB::transaction(function () use ($subscription) {
            $fresh = UserSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null || ! $this->needsReset($fresh->load('plan'))) {
                return;
            }

            $fresh->update([
                'used_checks_count' => 0,
                'scans_reset_at' => now()->addDays(30),
            ]);

            $subscription->fill($fresh->only(['used_checks_count', 'scans_reset_at']));
        });
    }

    private function needsReset(UserSubscription $subscription): bool
    {
        return $subscription->plan->type === PlanType::Free
            && $subscription->scans_reset_at !== null
            && $subscription->scans_reset_at->isPast();
    }
}
