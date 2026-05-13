<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_plans_in_correct_order(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/plans');

        $response->assertOk();
        $types = array_column($response->json(), 'type');
        $this->assertSame(['free', 'basic', 'plus', 'premium'], $types);
    }

    public function test_uses_user_currency_for_price_id(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'eur');

        config(['services.stripe.prices' => [
            'basic' => ['eur' => 'price_eur_basic', 'usd' => 'price_usd_basic'],
            'plus' => ['eur' => 'price_eur_plus',  'usd' => 'price_usd_plus'],
            'premium' => ['eur' => 'price_eur_premium', 'usd' => 'price_usd_premium'],
        ]]);

        $response = $this->apiAs($user)->getJson('/api/plans');

        $byType = collect($response->json())->keyBy('type');
        $this->assertSame('price_eur_plus', $byType['plus']['price_id']);
        $this->assertSame('eur', $byType['plus']['currency']);
    }

    public function test_falls_back_to_usd_when_currency_price_not_configured(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'gbp');

        config(['services.stripe.prices' => [
            'plus' => ['usd' => 'price_usd_plus'],
        ]]);

        $response = $this->apiAs($user)->getJson('/api/plans');

        $byType = collect($response->json())->keyBy('type');
        $this->assertSame('price_usd_plus', $byType['plus']['price_id']);
    }

    public function test_price_id_is_null_when_no_price_configured_at_all(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();

        config(['services.stripe.prices' => []]);

        $response = $this->apiAs($user)->getJson('/api/plans');

        $byType = collect($response->json())->keyBy('type');
        $this->assertNull($byType['plus']['price_id']);
    }

    public function test_free_plan_has_null_price_id(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();

        config(['services.stripe.prices' => [
            'plus' => ['usd' => 'price_usd_plus'],
        ]]);

        $response = $this->apiAs($user)->getJson('/api/plans');

        $byType = collect($response->json())->keyBy('type');
        $this->assertNull($byType['free']['price_id']);
    }

    public function test_plan_response_includes_required_fields(): void
    {
        $this->seedPlans();
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/plans');

        $plan = collect($response->json())->firstWhere('type', 'plus');
        $this->assertArrayHasKey('type', $plan);
        $this->assertArrayHasKey('label', $plan);
        $this->assertArrayHasKey('price', $plan);
        $this->assertArrayHasKey('currency', $plan);
        $this->assertArrayHasKey('price_id', $plan);
        $this->assertArrayHasKey('check_limit', $plan);
        $this->assertArrayHasKey('description', $plan);
    }

    private function seedPlans(): void
    {
        $this->makePlan(PlanType::Free);
        $this->makePlan(PlanType::Basic);
        $this->makePlan(PlanType::Plus);
        $this->makePlan(PlanType::Premium);
    }
}
