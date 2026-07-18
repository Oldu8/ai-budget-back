<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FeatureAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(FeatureAccessService::class);
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

    public function test_basic_plan_denied_for_analytics_dashboard(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));

        $result = $this->service->canAccess($user, 'analytics_dashboard');

        $this->assertFalse($result->allowed);
        $this->assertEquals('Analytics dashboard is not available on your plan.', $result->message);
    }

    public function test_plus_plan_can_access_analytics_dashboard(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $result = $this->service->canAccess($user, 'analytics_dashboard');

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
        $this->assertTrue($this->service->canAccess($user, 'analytics_dashboard')->allowed);
        $this->assertTrue($this->service->canAccess($user, 'voice_expense')->allowed);
    }

    public function test_premium_voice_expense_denied_when_quota_exceeded(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Premium, checkLimit: 10);
        $this->subscribe($user, $plan, usedChecks: 10);

        $result = $this->service->canAccess($user, 'voice_expense');

        $this->assertFalse($result->allowed);
        $this->assertSame('Scan limit reached for this billing period.', $result->message);
    }

    public function test_plus_plan_denied_for_voice_expense(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $result = $this->service->canAccess($user, 'voice_expense');

        $this->assertFalse($result->allowed);
        $this->assertSame('Voice expense entry is only available on Premium.', $result->message);
    }

    public function test_unknown_feature_is_denied(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium));

        $result = $this->service->canAccess($user, 'nonexistent_feature');

        $this->assertFalse($result->allowed);
    }

    public function test_assert_access_throws_403_when_denied(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        try {
            $this->service->assertAccess($user, 'recurring_expenses');
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_assert_access_does_not_throw_when_allowed(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $this->service->assertAccess($user, 'recurring_expenses');
        $this->addToAssertionCount(1);
    }

    public function test_assert_ai_scan_available_throws_when_denied(): void
    {
        $user = $this->makeAppUser();

        $this->expectException(HttpException::class);

        $this->service->assertAiScanAvailable($user);
    }

    public function test_assert_ai_scan_available_does_not_throw_when_allowed(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, checkLimit: 5));

        $this->service->assertAiScanAvailable($user);
        $this->addToAssertionCount(1);
    }
}
