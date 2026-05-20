<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\UserSubscription;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Tests\Support\StripePayloadBuilder;
use Tests\TestCase;

/**
 * Tests for the cancel-at-period-end access logic:
 * canceled subscriptions within their billing period still grant access.
 */
final class SubscriptionCancelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        ApiRequestor::setHttpClient(null);
    }

    // ──────────────────────────────────────────────
    // activeSubscription() — access check layer
    // ──────────────────────────────────────────────

    public function test_canceled_subscription_within_period_is_returned_as_active(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_test_001',
            'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        $result = $user->activeSubscription();

        $this->assertNotNull($result);
        $this->assertSame('sub_test_001', $result->payment_subscription_id);
    }

    public function test_canceled_subscription_past_period_is_not_returned(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_test_002',
            'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        $this->assertNull($user->activeSubscription());
    }

    public function test_active_subscription_still_returned_as_regression_guard(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);

        $result = $user->activeSubscription();

        $this->assertNotNull($result);
        $this->assertSame($sub->id, $result->id);
    }

    // ──────────────────────────────────────────────
    // onSubscriptionDeleted webhook
    // ──────────────────────────────────────────────

    public function test_subscription_deleted_with_future_period_defers_free_plan(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_defer_001']);
        $plan = $this->makePlan(PlanType::Plus);
        $priceId = 'price_plus_defer';
        config(['services.stripe.prices.plus.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_defer_001',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_defer_001',
            subscriptionId: 'sub_defer_001',
            priceId: $priceId,
            currentPeriodEnd: now()->addDays(25)->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_defer_001')->first();
        $this->assertSame('canceled', $sub->status->name);
        $this->assertTrue((bool) $sub->cancel_at_period_end);

        // Free plan must NOT be created — period hasn't ended yet
        $this->assertFalse(
            UserSubscription::where('app_user_id', $user->id)
                ->whereNull('payment_subscription_id')
                ->exists()
        );
    }

    public function test_subscription_deleted_with_past_period_creates_free_plan_immediately(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_past_001']);
        $plan = $this->makePlan(PlanType::Plus);
        $this->makePlan(PlanType::Free);
        $priceId = 'price_plus_past';
        config(['services.stripe.prices.plus.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_past_001',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_past_001',
            subscriptionId: 'sub_past_001',
            priceId: $priceId,
            currentPeriodEnd: now()->subDay()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame(
            'canceled',
            UserSubscription::where('payment_subscription_id', 'sub_past_001')->first()->status->name
        );

        $this->assertTrue(
            UserSubscription::where('app_user_id', $user->id)
                ->whereNull('payment_subscription_id')
                ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Free->value))
                ->exists()
        );
    }

    // ──────────────────────────────────────────────
    // FeatureAccessService — end-to-end access check
    // ──────────────────────────────────────────────

    public function test_feature_access_granted_for_canceled_within_period_user(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus, checkLimit: 10);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_feat_001',
            'current_period_end' => now()->addDays(5),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        $result = (new FeatureAccessService)->canAccess($user, 'recurring_expenses');

        $this->assertTrue($result->allowed);
    }

    public function test_feature_access_denied_for_canceled_past_period_user(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus, checkLimit: 10);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_feat_002',
            'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        $result = (new FeatureAccessService)->canAccess($user, 'recurring_expenses');

        $this->assertFalse($result->allowed);
    }

    // ──────────────────────────────────────────────
    // Middleware — lazy free plan provisioning
    // ──────────────────────────────────────────────

    public function test_middleware_creates_free_plan_when_user_has_no_subscription(): void
    {
        $user = $this->makeAppUser();
        $this->makePlan(PlanType::Free);
        Status::firstOrCreate(['name' => 'active']);

        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        Http::fake([
            $supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $user->supabase_user_id,
                'email' => $user->email,
                'email_confirmed_at' => now()->toIso8601String(),
            ], 200),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer test-token'])
            ->getJson('/api/me')
            ->assertOk();

        $this->assertTrue(
            UserSubscription::where('app_user_id', $user->id)
                ->whereNull('payment_subscription_id')
                ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Free->value))
                ->exists()
        );
    }

    public function test_middleware_does_not_create_free_plan_for_canceled_within_period_user(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_mid_001',
            'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        Http::fake([
            $supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $user->supabase_user_id,
                'email' => $user->email,
                'email_confirmed_at' => now()->toIso8601String(),
            ], 200),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer test-token'])
            ->getJson('/api/me')
            ->assertOk();

        // Only the original paid subscription should exist — no free plan created
        $this->assertSame(1, UserSubscription::where('app_user_id', $user->id)->count());
    }

    /** @test */
    public function test_active_paid_subscription_takes_priority_over_free_plan_after_resubscribe(): void
    {
        $statuses = $this->seedStatuses();
        $user = $this->makeAppUser();
        $freePlan = $this->makePlan(PlanType::Free);
        $plusPlan = $this->makePlan(PlanType::Plus);

        // Free plan exists (provisioned after previous cancellation period expired)
        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $freePlan->id,
            'status_id' => $statuses['active'],
            'payment_subscription_id' => null,
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        // User re-subscribes — new paid subscription created
        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plusPlan->id,
            'status_id' => $statuses['active'],
            'payment_subscription_id' => 'sub_resubscribe_001',
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        $active = $user->activeSubscription();

        $this->assertNotNull($active);
        $this->assertSame('sub_resubscribe_001', $active->payment_subscription_id);
        $this->assertSame(PlanType::Plus, $active->plan->type);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /** @return array<string, int> name → id */
    private function seedStatuses(): array
    {
        $names = ['active', 'canceled', 'past_due', 'trialing', 'pending'];
        $result = [];
        foreach ($names as $name) {
            $result[$name] = Status::firstOrCreate(['name' => $name])->id;
        }

        return $result;
    }
}
