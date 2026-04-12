<?php

declare(strict_types=1);

namespace App\AccessGuard;

use App\Models\Plan;
use App\Models\UserSubscription;

/**
 * Immutable DTO for feature access check results.
 */
readonly class FeatureAccessResult
{
    public function __construct(
        public bool $allowed,
        public ?string $message = null,
        public ?UserSubscription $subscription = null,
        public ?Plan $plan = null,
        public ?int $scans_remaining = null,
        public ?int $scans_limit = null,
        public ?string $period_end = null,
    ) {}

    /** @param array<string, mixed> $extra */
    public static function allowed(?UserSubscription $subscription, ?Plan $plan, array $extra = []): self
    {
        return new self(
            allowed: true,
            message: null,
            subscription: $subscription,
            plan: $plan,
            scans_remaining: $extra['scans_remaining'] ?? null,
            scans_limit: $extra['scans_limit'] ?? null,
            period_end: $extra['period_end'] ?? null,
        );
    }

    public static function denied(string $message): self
    {
        return new self(
            allowed: false,
            message: $message,
            subscription: null,
            plan: null,
        );
    }
}
