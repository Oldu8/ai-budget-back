<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Services\FeatureAccessService;
use App\Services\ScanQuotaResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanQuotaResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScanQuotaResetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ScanQuotaResetService::class);
    }

    public function test_free_plan_resets_quota_when_scans_reset_at_is_past(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Free, checkLimit: 20);
        $subscription = $this->subscribe($user, $plan, usedChecks: 20);
        $subscription->update(['scans_reset_at' => now()->subDay()]);

        $this->service->ensureReset($subscription->fresh(['plan']));

        $subscription->refresh();
        $this->assertSame(0, $subscription->used_checks_count);
        $this->assertTrue($subscription->scans_reset_at->isFuture());
    }

    public function test_free_plan_does_not_reset_when_scans_reset_at_is_future(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Free, checkLimit: 20);
        $subscription = $this->subscribe($user, $plan, usedChecks: 15);
        $subscription->update(['scans_reset_at' => now()->addDays(10)]);

        $this->service->ensureReset($subscription->fresh(['plan']));

        $subscription->refresh();
        $this->assertSame(15, $subscription->used_checks_count);
    }

    public function test_paid_plan_does_not_reset_on_rolling_period(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Basic, checkLimit: 200);
        $subscription = $this->subscribe($user, $plan, usedChecks: 150);
        $subscription->update(['scans_reset_at' => now()->subDay()]);

        $this->service->ensureReset($subscription->fresh(['plan']));

        $subscription->refresh();
        $this->assertSame(150, $subscription->used_checks_count);
    }

    public function test_feature_access_allows_scan_after_free_quota_reset(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Free, checkLimit: 5);
        $subscription = $this->subscribe($user, $plan, usedChecks: 5);
        $subscription->update(['scans_reset_at' => now()->subDay()]);

        $result = $this->app->make(FeatureAccessService::class)->canAccess($user, 'ai_scan');

        $this->assertTrue($result->allowed);
        $this->assertSame(5, $result->scans_remaining);
    }
}
