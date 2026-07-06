<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Tests\Support\StripeHttpClientFake;
use Tests\Support\StripePayloadBuilder;
use Tests\TestCase;

final class StripeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset Stripe HTTP client so it doesn't affect other tests
        ApiRequestor::setHttpClient(null);
    }

    // ──────────────────────────────────────────────
    // checkout
    // ──────────────────────────────────────────────

    public function test_checkout_returns_409_when_user_has_active_paid_subscription(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_existing']);

        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertStatus(409);
    }

    public function test_checkout_not_blocked_for_canceled_within_period_user(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_ck_001',
            'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => true,
            'used_checks_count' => 0,
        ]);

        // Clear prices so the check-wall at 409 is passed and 422 is returned instead
        config(['services.stripe.prices' => []]);

        // Must be 422 (no price configured), NOT 409 (active subscription guard)
        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertStatus(422);
    }

    public function test_checkout_returns_422_when_no_stripe_price_configured(): void
    {
        $user = $this->makeAppUser();

        // Clear any price config so no price ID is found
        config(['services.stripe.prices' => []]);

        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertStatus(422);
    }

    public function test_checkout_returns_checkout_url_on_success(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_existing']);

        config(['services.stripe.prices.basic.usd' => 'price_test_basic_usd']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/checkout/sessions' => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/test',
            ],
        ]));

        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertOk()
            ->assertJsonStructure(['checkout_url']);
    }

    public function test_checkout_creates_stripe_customer_when_none_exists(): void
    {
        $user = $this->makeAppUser();
        config(['services.stripe.prices.basic.usd' => 'price_test_basic_usd']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/customers' => ['id' => 'cus_new_123', 'object' => 'customer'],
            '/v1/checkout/sessions' => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/test',
            ],
        ]));

        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertOk();

        $this->assertSame('cus_new_123', $user->fresh()->payment_customer_id);
    }

    // ──────────────────────────────────────────────
    // portal
    // ──────────────────────────────────────────────

    public function test_portal_returns_400_when_user_has_no_customer_id(): void
    {
        $user = $this->makeAppUser();

        $this->apiAs($user)->postJson('/api/stripe/portal')
            ->assertStatus(400);
    }

    public function test_portal_returns_portal_url_on_success(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test_123',
                'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/test',
            ],
        ]));

        $this->apiAs($user)->postJson('/api/stripe/portal')
            ->assertOk()
            ->assertJsonStructure(['portal_url']);
    }

    public function test_portal_with_plan_type_requests_subscription_update_confirm_flow(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_flow_001']);

        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        $fake = new StripeHttpClientFake([
            '/v1/subscriptions/sub_flow_001' => [
                'id' => 'sub_flow_001',
                'object' => 'subscription',
                'items' => [
                    'object' => 'list',
                    'data' => [
                        [
                            'id' => 'si_test_001',
                            'object' => 'subscription_item',
                            'price' => ['id' => 'price_test_basic_usd', 'object' => 'price'],
                        ],
                    ],
                ],
            ],
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test_123',
                'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/test',
            ],
        ]);
        ApiRequestor::setHttpClient($fake);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()
            ->assertJsonStructure(['portal_url']);

        $portalRequest = collect($fake->requests)
            ->first(fn (array $r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
        $this->assertNotNull($portalRequest);
        $this->assertSame('subscription_update_confirm', $portalRequest['params']['flow_data']['type']);
        $this->assertSame('sub_flow_001', $portalRequest['params']['flow_data']['subscription_update_confirm']['subscription']);
        $this->assertSame('price_test_plus_usd', $portalRequest['params']['flow_data']['subscription_update_confirm']['items'][0]['price']);
        $this->assertSame('redirect', $portalRequest['params']['flow_data']['after_completion']['type']);
    }

    public function test_portal_with_plan_type_falls_back_to_plain_portal_without_active_subscription(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);

        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        $fake = new StripeHttpClientFake([
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test_123',
                'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/test',
            ],
        ]);
        ApiRequestor::setHttpClient($fake);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()
            ->assertJsonStructure(['portal_url']);

        $portalRequest = collect($fake->requests)
            ->first(fn (array $r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
        $this->assertNotNull($portalRequest);
        $this->assertArrayNotHasKey('flow_data', $portalRequest['params']);
    }

    public function test_portal_rejects_free_plan_type(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'free'])
            ->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // webhook — signature
    // ──────────────────────────────────────────────

    public function test_webhook_returns_400_on_invalid_signature(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 'invalid_sig', 'CONTENT_TYPE' => 'application/json'],
            '{"id":"evt_test","type":"ping"}'
        )->assertStatus(400);
    }

    // ──────────────────────────────────────────────
    // webhook — checkout.session.completed
    // ──────────────────────────────────────────────

    public function test_checkout_session_completed_stores_checkout_log(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_log']);

        [$payload, $sig] = StripePayloadBuilder::checkoutSessionCompleted(
            sessionId: 'cs_log_001',
            customerId: 'cus_test_log',
            appUserId: $user->id,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('stripe_checkout_logs', [
            'stripe_checkout_session_id' => 'cs_log_001',
            'stripe_customer_id' => 'cus_test_log',
            'app_user_id' => $user->id,
        ]);
    }

    // ──────────────────────────────────────────────
    // webhook — customer.subscription.created
    // ──────────────────────────────────────────────

    public function test_subscription_created_creates_user_subscription(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sub_create']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_basic_usd_test';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_sub_create',
            subscriptionId: 'sub_test_created',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'app_user_id' => $user->id,
            'payment_subscription_id' => 'sub_test_created',
        ]);
    }

    public function test_subscription_created_cancels_free_plan_subscription(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sub_free_cancel']);
        $freePlan = $this->makePlan(PlanType::Free);
        $paidPlan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_basic_usd_test2';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $freeSub = $this->subscribe($user, $freePlan);
        // Active free subscription must have no payment_subscription_id
        $this->assertNull($freeSub->payment_subscription_id);

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_sub_free_cancel',
            subscriptionId: 'sub_paid_001',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('canceled', $freeSub->fresh()->status->name);
    }

    // ──────────────────────────────────────────────
    // webhook — customer.subscription.updated
    // ──────────────────────────────────────────────

    public function test_subscription_updated_updates_existing_record(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sub_upd']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_basic_upd';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_upd_001',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        $newPeriodEnd = now()->addMonth()->timestamp;

        [$payload, $sig] = StripePayloadBuilder::subscriptionUpdated(
            customerId: 'cus_sub_upd',
            subscriptionId: 'sub_upd_001',
            priceId: $priceId,
            currentPeriodEnd: $newPeriodEnd,
            status: 'active',
            cancelAtPeriodEnd: true,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertTrue($sub->fresh()->cancel_at_period_end);
    }

    public function test_subscription_updated_creates_record_when_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sub_upd_new']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_basic_upd_new';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        [$payload, $sig] = StripePayloadBuilder::subscriptionUpdated(
            customerId: 'cus_sub_upd_new',
            subscriptionId: 'sub_upd_new_001',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'payment_subscription_id' => 'sub_upd_new_001',
        ]);
    }

    // ──────────────────────────────────────────────
    // webhook — customer.subscription.deleted
    // ──────────────────────────────────────────────

    public function test_subscription_deleted_sets_status_to_canceled(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sub_del']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_basic_del';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_del_001',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addDays(5),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_sub_del',
            subscriptionId: 'sub_del_001',
            priceId: $priceId,
            currentPeriodEnd: now()->addDays(5)->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('canceled', $sub->fresh()->status->name);
    }

    // ──────────────────────────────────────────────
    // webhook — invoice.paid
    // ──────────────────────────────────────────────

    public function test_invoice_paid_creates_payment_record(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_inv_paid']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_inv_paid',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 5,
        ]);

        [$payload, $sig] = StripePayloadBuilder::invoicePaid(
            customerId: 'cus_inv_paid',
            invoiceId: 'in_test_paid_001',
            subscriptionId: 'sub_inv_paid',
            amountPaid: 999,
            currency: 'usd',
            billingReason: 'subscription_cycle',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('payments', [
            'payment_invoice_id' => 'in_test_paid_001',
            'app_user_id' => $user->id,
            'amount' => 999,
        ]);
    }

    public function test_invoice_paid_resets_used_checks_on_subscription_cycle(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_inv_reset']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_inv_reset',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 7,
        ]);

        [$payload, $sig] = StripePayloadBuilder::invoicePaid(
            customerId: 'cus_inv_reset',
            invoiceId: 'in_test_reset_001',
            subscriptionId: 'sub_inv_reset',
            billingReason: 'subscription_cycle',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame(0, $sub->fresh()->used_checks_count);
    }

    // ──────────────────────────────────────────────
    // webhook — invoice.payment_failed
    // ──────────────────────────────────────────────

    public function test_invoice_payment_failed_sets_status_to_past_due(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_inv_fail']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_inv_fail',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::invoicePaymentFailed(
            customerId: 'cus_inv_fail',
            invoiceId: 'in_fail_001',
            subscriptionId: 'sub_inv_fail',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('past_due', $sub->fresh()->status->name);
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
