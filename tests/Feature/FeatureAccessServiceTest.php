<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\UserSubscription;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeatureAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FeatureAccessService();
    }

    public function test_denied_when_no_subscription(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->canAccess($user, 'ai_scan');

        $this->assertFalse($result->allowed);
        $this->assertEquals('Subscription required to scan.', $result->message);
    }

    public function test_free_plan_can_access_ai_scan_within_quota(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Free, checkLimit: 5);
        $this->subscribe($user, $plan, usedChecks: 0);

        $result = $this->service->canAccess($user, 'ai_scan');

        $this->assertTrue($result->allowed);
        $this->assertEquals(5, $result->scans_remaining);
        $this->assertEquals(5, $result->scans_limit);
    }

    public function test_ai_scan_denied_when_quota_exceeded(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Free, checkLimit: 5);
        $this->subscribe($user, $plan, usedChecks: 5);

        $result = $this->service->canAccess($user, 'ai_scan');

        $this->assertFalse($result->allowed);
        $this->assertEquals('Scan limit reached for this billing period.', $result->message);
    }

    public function test_free_plan_denied_for_recurring_expenses(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        $result = $this->service->canAccess($user, 'recurring_expenses');

        $this->assertFalse($result->allowed);
        $this->assertEquals('Recurring expenses are not available on your plan.', $result->message);
    }

    public function test_plus_plan_can_access_recurring_expenses(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $result = $this->service->canAccess($user, 'recurring_expenses');

        $this->assertTrue($result->allowed);
    }

    public function test_free_plan_denied_for_csv_export(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        $result = $this->service->canAccess($user, 'csv_export');

        $this->assertFalse($result->allowed);
        $this->assertEquals('CSV export is not available on your plan.', $result->message);
    }

    public function test_basic_plan_can_access_csv_export(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));

        $result = $this->service->canAccess($user, 'csv_export');

        $this->assertTrue($result->allowed);
    }

    public function test_premium_plan_has_access_to_all_features(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium, checkLimit: 100), usedChecks: 0);

        $this->assertTrue($this->service->canAccess($user, 'ai_scan')->allowed);
        $this->assertTrue($this->service->canAccess($user, 'recurring_expenses')->allowed);
        $this->assertTrue($this->service->canAccess($user, 'csv_export')->allowed);
    }

    public function test_unknown_feature_is_denied(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium));

        $result = $this->service->canAccess($user, 'nonexistent_feature');

        $this->assertFalse($result->allowed);
    }

}
