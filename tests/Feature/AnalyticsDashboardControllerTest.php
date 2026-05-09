<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertUnauthorized();
    }

    public function test_free_plan_is_forbidden(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertForbidden();
    }

    public function test_basic_plan_is_forbidden(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertForbidden();
    }

    public function test_plus_plan_returns_analytics_payload(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertOk()->assertJsonStructure([
            'month',
            'currency',
            'period' => ['from', 'to', 'previous_from', 'previous_to'],
            'overview' => ['total_spent', 'previous_month_total', 'average_per_day', 'biggest_category',
                'difference_amount', 'difference_percent', 'direction'],
            'categories',
            'trends' => ['categories', 'subcategories'],
        ]);
    }

    public function test_missing_month_param_returns_422(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard');

        $response->assertUnprocessable();
    }

    public function test_invalid_month_format_returns_422_with_message(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-3');

        $response->assertUnprocessable()
            ->assertJsonPath('errors.month.0', 'The month must be a valid month in YYYY-MM format.');
    }

    public function test_premium_plan_returns_analytics(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium));

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertOk();
    }

    public function test_authenticated_user_without_subscription_is_forbidden(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/analytics/dashboard?month=2024-03');

        $response->assertForbidden();
    }
}
