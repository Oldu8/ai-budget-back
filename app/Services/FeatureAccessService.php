<?php

declare(strict_types=1);

namespace App\Services;

use App\AccessGuard\FeatureAccessResult;
use App\Models\AppUser;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FeatureAccessService
{
    public function __construct(
        // [SOLID:D] Reset logic injected — this service only gates access
        private ScanQuotaResetService $scanQuotaReset,
    ) {}

    public function canAccess(AppUser $user, string $feature): FeatureAccessResult
    {
        $config = Config::get("feature_access.features.{$feature}");
        if (! $config) {
            return FeatureAccessResult::denied('Feature not available.');
        }

        $subscription = $user->activeSubscription()?->load('plan');
        $plan = $subscription?->plan;

        if (! $subscription || ! $plan) {
            $message = $config['messages']['no_subscription'] ?? $config['messages']['denied'] ?? 'Subscription required.';

            return FeatureAccessResult::denied($message);
        }

        $allowedPlans = $config['allowed_plans'] ?? [];
        $planType = $plan->type->value;
        if (! in_array($planType, $allowedPlans, true)) {
            return FeatureAccessResult::denied($config['messages']['denied'] ?? 'Feature not available on your plan.');
        }

        if (! empty($config['requires_quota'])) {
            $this->scanQuotaReset->ensureReset($subscription);

            $used = $subscription->used_checks_count;
            $limit = $plan->check_limit;
            if ($used >= $limit) {
                return FeatureAccessResult::denied($config['messages']['quota_exceeded'] ?? 'Limit reached for this billing period.');
            }
            $extra = [
                'scans_remaining' => max(0, $limit - $used),
                'scans_limit' => $limit,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ];

            return FeatureAccessResult::allowed($subscription, $plan, $extra);
        }

        return FeatureAccessResult::allowed($subscription, $plan);
    }

    public function canUseAiScan(AppUser $user): FeatureAccessResult
    {
        return $this->canAccess($user, 'ai_scan');
    }

    public function assertAccess(AppUser $user, string $feature): void
    {
        $result = $this->canAccess($user, $feature);
        if (! $result->allowed) {
            throw new HttpException(403, $result->message ?? 'Access denied.');
        }
    }

    public function assertAiScanAvailable(AppUser $user): void
    {
        $this->assertAccess($user, 'ai_scan');
    }
}
