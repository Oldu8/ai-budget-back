<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\UserSubscription;
use App\Services\EnsureDefaultSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
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

    public function test_checkout_returns_500_when_stripe_session_creation_fails(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_existing']);
        config(['services.stripe.prices.basic.usd' => 'price_test_basic_usd']);

        // No /v1/checkout/sessions response registered — the fake 400s, Stripe SDK throws.
        ApiRequestor::setHttpClient(new StripeHttpClientFake([]));

        $this->apiAs($user)->postJson('/api/stripe/checkout', ['plan_type' => 'basic'])
            ->assertStatus(500);
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

    public function test_portal_returns_500_when_stripe_session_creation_fails(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);

        // No /v1/billing_portal/sessions response registered — the fake 400s.
        ApiRequestor::setHttpClient(new StripeHttpClientFake([]));

        $this->apiAs($user)->postJson('/api/stripe/portal')
            ->assertStatus(500);
    }

    public function test_portal_falls_back_when_subscription_has_no_items(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_no_items']);
        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/subscriptions/sub_no_items' => [
                'id' => 'sub_no_items',
                'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => []],
            ],
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/test',
            ],
        ]));

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()->assertJsonStructure(['portal_url']);
    }

    public function test_portal_falls_back_when_already_on_target_price(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_same_price']);
        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        $fake = new StripeHttpClientFake([
            '/v1/subscriptions/sub_same_price' => [
                'id' => 'sub_same_price',
                'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test', 'object' => 'subscription_item',
                    'price' => ['id' => 'price_test_plus_usd', 'object' => 'price'],
                ]]],
            ],
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/test',
            ],
        ]);
        ApiRequestor::setHttpClient($fake);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()->assertJsonStructure(['portal_url']);

        $portalRequest = collect($fake->requests)->first(fn (array $r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
        $this->assertArrayNotHasKey('flow_data', $portalRequest['params']);
    }

    public function test_portal_falls_back_when_subscription_retrieve_fails(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_retrieve_fails']);
        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        // No response for /v1/subscriptions/sub_retrieve_fails — retrieve() throws, caught, falls back.
        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/test',
            ],
        ]));

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()->assertJsonStructure(['portal_url']);
    }

    public function test_portal_retries_without_flow_data_when_stripe_rejects_it(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_test_123']);
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_flow_rejected']);
        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        $fake = new StripeHttpClientFake([
            '/v1/subscriptions/sub_flow_rejected' => [
                'id' => 'sub_flow_rejected',
                'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test', 'object' => 'subscription_item',
                    'price' => ['id' => 'price_test_basic_usd', 'object' => 'price'],
                ]]],
            ],
            // First call (with flow_data) is rejected by Stripe; the retry without it succeeds.
            '/v1/billing_portal/sessions' => function (array $params) {
                if (isset($params['flow_data'])) {
                    return ['status' => 400, 'body' => ['error' => ['message' => 'flow_data rejected']]];
                }

                return ['body' => ['id' => 'bps_retry', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/retry']];
            },
        ]);
        ApiRequestor::setHttpClient($fake);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()
            ->assertJson(['portal_url' => 'https://billing.stripe.com/retry']);

        $portalCalls = collect($fake->requests)->filter(fn (array $r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
        $this->assertCount(2, $portalCalls);
    }

    public function test_portal_falls_back_to_usd_price_when_users_currency_has_no_configured_price(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_currency_fallback']);
        $this->makeSettings($user, 'eur');
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_currency_fallback']);

        // No services.stripe.prices.plus.eur configured — must fall back to the usd price.
        config(['services.stripe.prices.plus.usd' => 'price_test_plus_usd']);

        $fake = new StripeHttpClientFake([
            '/v1/subscriptions/sub_currency_fallback' => [
                'id' => 'sub_currency_fallback',
                'object' => 'subscription',
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_test_currency',
                        'object' => 'subscription_item',
                        'price' => ['id' => 'price_test_basic_usd', 'object' => 'price'],
                    ]],
                ],
            ],
            '/v1/billing_portal/sessions' => [
                'id' => 'bps_test_currency', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/currency',
            ],
        ]);
        ApiRequestor::setHttpClient($fake);

        $this->apiAs($user)->postJson('/api/stripe/portal', ['plan_type' => 'plus'])
            ->assertOk()
            ->assertJsonStructure(['portal_url']);

        $portalRequest = collect($fake->requests)
            ->first(fn (array $r) => str_contains($r['url'], '/v1/billing_portal/sessions'));
        $this->assertNotNull($portalRequest);
        $this->assertSame('price_test_plus_usd', $portalRequest['params']['flow_data']['subscription_update_confirm']['items'][0]['price']);
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

    public function test_webhook_returns_400_on_invalid_json_with_a_valid_signature(): void
    {
        $malformed = '{not valid json';
        $sig = StripePayloadBuilder::sign($malformed);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $malformed
        )->assertStatus(400)->assertJson(['message' => 'Invalid payload']);
    }

    public function test_webhook_returns_500_when_event_handling_throws_unexpectedly(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_boom']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_boom';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_boom',
            'status_id' => Status::where('name', 'active')->value('id'),
            'current_period_end' => now()->subDay(), // already ended -> triggers ensureFreeSubscription
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        // A real collaborator failing unexpectedly must be caught by the webhook's outer handler, not crash bare.
        $this->app->instance(
            EnsureDefaultSubscriptionService::class,
            Mockery::mock(EnsureDefaultSubscriptionService::class, function ($mock) {
                $mock->shouldReceive('ensureFreeSubscription')->andThrow(new \RuntimeException('boom'));
            }),
        );

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_boom',
            subscriptionId: 'sub_boom',
            priceId: $priceId,
            currentPeriodEnd: now()->subDay()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertStatus(500)->assertJson(['message' => 'Webhook processing failed']);
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

    public function test_checkout_session_completed_notifies_for_unknown_customer(): void
    {
        // appUserId 0 -> the controller treats it as "no app user" (avoids an FK
        // violation on stripe_checkout_logs.app_user_id for a non-existent id).
        [$payload, $sig] = StripePayloadBuilder::checkoutSessionCompleted(
            sessionId: 'cs_unknown_001',
            customerId: 'cus_does_not_exist',
            appUserId: 0,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        // Best-effort logging only; the webhook must still succeed.
        $this->assertDatabaseHas('stripe_checkout_logs', ['stripe_checkout_session_id' => 'cs_unknown_001']);
    }

    public function test_checkout_session_completed_falls_back_to_client_reference_id_when_metadata_app_user_id_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_client_ref_fallback']);

        $payload = json_encode([
            'id' => 'evt_client_ref', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_client_ref_001', 'object' => 'checkout.session',
                'customer' => 'cus_client_ref_fallback',
                'client_reference_id' => (string) $user->id,
                'metadata' => ['plan_type' => 'basic', 'currency' => 'usd'], // no app_user_id key
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('stripe_checkout_logs', [
            'stripe_checkout_session_id' => 'cs_client_ref_001',
            'app_user_id' => $user->id,
        ]);
    }

    public function test_checkout_session_completed_persist_failure_is_non_fatal(): void
    {
        // metadata.app_user_id references an AppUser id that doesn't exist -> the
        // stripe_checkout_logs.app_user_id foreign key rejects the insert. The persist
        // is explicitly best-effort (see the try/catch comment); the webhook must still
        // succeed and no row should be written.
        $payload = json_encode([
            'id' => 'evt_persist_fails', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_persist_fails_001', 'object' => 'checkout.session',
                'customer' => 'cus_persist_fails',
                'client_reference_id' => '999999999',
                'metadata' => ['app_user_id' => '999999999', 'plan_type' => 'basic', 'currency' => 'usd'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('stripe_checkout_logs', ['stripe_checkout_session_id' => 'cs_persist_fails_001']);
    }

    public function test_checkout_session_completed_skips_user_lookup_when_customer_is_missing(): void
    {
        config(['services.discord.errors_webhook_url' => 'https://discord.example.com/errors']);

        $payload = json_encode([
            'id' => 'evt_no_customer', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_no_customer_001', 'object' => 'checkout.session',
                'customer' => null,
                'client_reference_id' => null,
                'metadata' => ['plan_type' => 'basic', 'currency' => 'usd'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('stripe_checkout_logs', [
            'stripe_checkout_session_id' => 'cs_no_customer_001',
            'stripe_customer_id' => null,
            'app_user_id' => null,
        ]);
        // No stripe_customer_id at all -> the "unknown customer" notify guard must never fire.
        Http::assertNothingSent();
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

    public function test_subscription_created_leaves_free_subscription_active_when_canceled_status_row_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_no_canceled_status']);
        $freePlan = $this->makePlan(PlanType::Free);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_no_canceled_status';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $active = Status::firstOrCreate(['name' => 'active']); // deliberately no 'canceled' row

        $freeSub = $this->subscribe($user, $freePlan);
        $this->assertNull($freeSub->payment_subscription_id);

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_no_canceled_status',
            subscriptionId: 'sub_no_canceled_status',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        // New paid subscription is created fine (its own status_id resolves)...
        $this->assertDatabaseHas('user_subscriptions', [
            'payment_subscription_id' => 'sub_no_canceled_status',
            'status_id' => $active->id,
        ]);
        // ...but cancelFreeSubscription bails early since there's no local 'canceled' status row.
        $this->assertSame('active', $freeSub->fresh()->status->name);
    }

    public function test_subscription_created_notifies_for_unknown_customer(): void
    {
        config(['services.stripe.prices.basic.usd' => 'price_unknown_customer']);
        $this->seedStatuses();

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_does_not_exist',
            subscriptionId: 'sub_unknown_cust',
            priceId: 'price_unknown_customer',
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('user_subscriptions', ['payment_subscription_id' => 'sub_unknown_cust']);
    }

    public function test_subscription_created_handles_missing_price_id(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_no_price']);
        $this->seedStatuses();

        $payload = json_encode([
            'id' => 'evt_no_price', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_no_price', 'object' => 'subscription', 'customer' => 'cus_no_price',
                'status' => 'active', 'cancel_at_period_end' => false, 'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['object' => 'list', 'data' => []],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('user_subscriptions', ['payment_subscription_id' => 'sub_no_price']);
    }

    public function test_subscription_created_handles_unmapped_price(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_unmapped_price']);
        $this->seedStatuses();
        // No plan configured for this price at all.

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_unmapped_price',
            subscriptionId: 'sub_unmapped_price',
            priceId: 'price_nobody_maps_to_this',
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('user_subscriptions', ['payment_subscription_id' => 'sub_unmapped_price']);
    }

    public function test_subscription_created_returns_ok_when_local_status_row_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_no_status_row']);
        $this->makePlan(PlanType::Basic);
        config(['services.stripe.prices.basic.usd' => 'price_no_status_row']);
        // Deliberately not seeding statuses — 'active' has no local row.

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_no_status_row',
            subscriptionId: 'sub_no_status_row',
            priceId: 'price_no_status_row',
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('user_subscriptions', ['payment_subscription_id' => 'sub_no_status_row']);
    }

    public function test_subscription_created_maps_incomplete_status_to_pending(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_incomplete']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_incomplete';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(
            customerId: 'cus_incomplete',
            subscriptionId: 'sub_incomplete',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
            status: 'incomplete',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_incomplete')->firstOrFail();
        $this->assertSame('pending', $sub->status->name);
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

    public function test_subscription_updated_changes_plan_when_price_changes(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_plan_change']);
        $oldPlan = $this->makePlan(PlanType::Basic);
        $newPlan = $this->makePlan(PlanType::Plus);
        $oldPriceId = 'price_basic_change';
        $newPriceId = 'price_plus_change';
        config(['services.stripe.prices.basic.usd' => $oldPriceId]);
        config(['services.stripe.prices.plus.usd' => $newPriceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $oldPlan->id,
            'payment_subscription_id' => 'sub_plan_change',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionUpdated(
            customerId: 'cus_plan_change',
            subscriptionId: 'sub_plan_change',
            priceId: $newPriceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame($newPlan->id, $sub->fresh()->plan_id);
    }

    public function test_subscription_updated_returns_ok_when_local_status_row_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_upd_no_status']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_upd_no_status';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $active = Status::firstOrCreate(['name' => 'active']); // only seed the status the local row already has

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_upd_no_status',
            'status_id' => $active->id, 'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionUpdated(
            customerId: 'cus_upd_no_status',
            subscriptionId: 'sub_upd_no_status',
            priceId: $priceId,
            currentPeriodEnd: now()->addMonth()->timestamp,
            status: 'trialing', // no local 'trialing' row seeded
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        // Untouched: requireStatusId returned null, so onSubscriptionUpdated bailed before updating.
        $this->assertSame($active->id, $sub->fresh()->status_id);
    }

    public function test_subscription_updated_keeps_old_plan_when_new_price_is_unmapped(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_upd_unmapped']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_upd_unmapped';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_upd_unmapped',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionUpdated(
            customerId: 'cus_upd_unmapped',
            subscriptionId: 'sub_upd_unmapped',
            priceId: 'price_nobody_maps_to_this',
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame($plan->id, $sub->fresh()->plan_id);
    }

    // ──────────────────────────────────────────────
    // webhook — customer.subscription.deleted
    // ──────────────────────────────────────────────

    public function test_subscription_deleted_with_no_local_record_returns_ok(): void
    {
        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_no_local_sub',
            subscriptionId: 'sub_never_seen',
            priceId: 'price_irrelevant',
            currentPeriodEnd: now()->addMonth()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();
    }

    public function test_subscription_deleted_defers_access_when_period_is_in_future(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_defer']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_defer';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_defer',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addDays(10),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_defer',
            subscriptionId: 'sub_defer',
            priceId: $priceId,
            currentPeriodEnd: now()->addDays(10)->timestamp, // still in the future
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $fresh = $sub->fresh();
        $this->assertSame('canceled', $fresh->status->name);
        $this->assertTrue($fresh->cancel_at_period_end);
        $this->assertTrue($fresh->current_period_end->isFuture());
    }

    public function test_subscription_deleted_provisions_free_plan_when_period_already_ended(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_ended']);
        $this->makePlan(PlanType::Free);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_ended';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_ended',
            'status_id' => $statuses['active'], 'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_ended',
            subscriptionId: 'sub_ended',
            priceId: $priceId,
            currentPeriodEnd: now()->subDay()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('canceled', $sub->fresh()->status->name);
        $this->assertDatabaseHas('user_subscriptions', [
            'app_user_id' => $user->id,
            'payment_subscription_id' => null, // the newly provisioned free subscription
        ]);
    }

    public function test_subscription_deleted_returns_ok_when_canceled_status_row_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_del_no_status']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_del_no_status';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $active = Status::firstOrCreate(['name' => 'active']); // no 'canceled' row

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_del_no_status',
            'status_id' => $active->id, 'current_period_end' => now()->addDays(5),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_del_no_status',
            subscriptionId: 'sub_del_no_status',
            priceId: $priceId,
            currentPeriodEnd: now()->addDays(5)->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame($active->id, $sub->fresh()->status_id);
    }

    /**
     * Account deletion cancels Stripe first, then soft-deletes the user. The resulting
     * customer.subscription.deleted webhook still finds the local row by payment_subscription_id,
     * but $local->appUser is null (SoftDeletes) — free-plan provisioning must be skipped quietly.
     */
    public function test_subscription_deleted_skips_free_plan_when_app_user_soft_deleted(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_soft_deleted']);
        $this->makePlan(PlanType::Free);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_soft_deleted';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_soft_deleted',
            'status_id' => $statuses['active'],
            'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => false,
            'used_checks_count' => 0,
        ]);

        $user->delete();

        [$payload, $sig] = StripePayloadBuilder::subscriptionDeleted(
            customerId: 'cus_soft_deleted',
            subscriptionId: 'sub_soft_deleted',
            priceId: $priceId,
            currentPeriodEnd: now()->subDay()->timestamp,
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('canceled', $sub->fresh()->status->name);
        $this->assertDatabaseMissing('user_subscriptions', [
            'app_user_id' => $user->id,
            'payment_subscription_id' => null,
        ]);
        $this->assertNull($sub->fresh()->appUser);
    }

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

    public function test_invoice_paid_returns_ok_when_no_subscription_id_present(): void
    {
        $payload = json_encode([
            'id' => 'evt_no_sub', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_no_sub', 'object' => 'invoice', 'customer' => 'cus_whatever',
                'subscription' => null, 'amount_paid' => 100, 'currency' => 'usd',
                'billing_reason' => 'manual', 'lines' => ['object' => 'list', 'data' => []],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('payments', ['payment_invoice_id' => 'in_no_sub']);
    }

    public function test_invoice_paid_notifies_when_no_local_subscription_found(): void
    {
        [$payload, $sig] = StripePayloadBuilder::invoicePaid(
            customerId: 'cus_no_local_for_invoice',
            invoiceId: 'in_orphan_001',
            subscriptionId: 'sub_never_synced',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseMissing('payments', ['payment_invoice_id' => 'in_orphan_001']);
    }

    /**
     * Stripe doesn't guarantee webhook delivery order — invoice.paid can arrive before
     * customer.subscription.created. onInvoicePaid() must self-heal via Subscription::retrieve
     * instead of just logging a warning and dropping the payment/status-recovery logic.
     */
    public function test_invoice_paid_self_heals_missing_subscription_via_stripe_retrieve(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_self_heal']);
        $plan = $this->makePlan(PlanType::Basic);
        $priceId = 'price_self_heal';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/subscriptions/sub_self_heal' => [
                'id' => 'sub_self_heal',
                'object' => 'subscription',
                'customer' => 'cus_self_heal',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'current_period_end' => now()->addMonth()->timestamp,
                'trial_start' => null,
                'trial_end' => null,
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_self_heal',
                        'object' => 'subscription_item',
                        'current_period_end' => now()->addMonth()->timestamp,
                        'price' => ['id' => $priceId, 'object' => 'price'],
                    ]],
                ],
            ],
        ]));

        [$payload, $sig] = StripePayloadBuilder::invoicePaid(
            customerId: 'cus_self_heal',
            invoiceId: 'in_self_heal_001',
            subscriptionId: 'sub_self_heal',
            amountPaid: 500,
            currency: 'usd',
            billingReason: 'subscription_create',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_self_heal',
        ]);
        $this->assertDatabaseHas('payments', [
            'payment_invoice_id' => 'in_self_heal_001',
            'app_user_id' => $user->id,
            'amount' => 500,
        ]);
    }

    public function test_invoice_paid_recovers_past_due_subscription_to_active(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_recover']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_recover',
            'status_id' => $statuses['past_due'], 'current_period_end' => now()->addDays(3),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::invoicePaid(
            customerId: 'cus_recover',
            invoiceId: 'in_recover_001',
            subscriptionId: 'sub_recover',
            billingReason: 'manual', // not subscription_cycle -> quota is not reset here
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame('active', $sub->fresh()->status->name);
    }

    public function test_invoice_paid_resolves_subscription_id_from_parent_subscription_details(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_new_shape']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_new_shape',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false, 'used_checks_count' => 3,
        ]);

        // Newer Stripe payload shape: no top-level `subscription`, nested under parent.subscription_details
        // (see docs/stripe-webhook-samples/invoice.paid.json).
        $payload = json_encode([
            'id' => 'evt_new_shape', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_new_shape', 'object' => 'invoice', 'customer' => 'cus_new_shape',
                'amount_paid' => 500, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle',
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_new_shape']],
                'lines' => ['object' => 'list', 'data' => []],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('payments', ['payment_invoice_id' => 'in_new_shape', 'app_user_id' => $user->id]);
        $this->assertSame(0, $sub->fresh()->used_checks_count);
    }

    public function test_invoice_paid_resolves_subscription_id_from_line_items_when_parent_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_line_shape']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_line_shape',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $payload = json_encode([
            'id' => 'evt_line_shape', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_line_shape', 'object' => 'invoice', 'customer' => 'cus_line_shape',
                'amount_paid' => 500, 'currency' => 'usd', 'billing_reason' => 'manual',
                'lines' => ['object' => 'list', 'data' => [[
                    'id' => 'il_1', 'object' => 'line_item',
                    'parent' => [
                        'type' => 'subscription_item_details',
                        'subscription_item_details' => ['subscription' => 'sub_line_shape', 'subscription_item' => 'si_1'],
                    ],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('payments', ['payment_invoice_id' => 'in_line_shape', 'app_user_id' => $user->id]);
    }

    public function test_invoice_paid_skips_non_matching_line_items_before_finding_subscription_id(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_mixed_lines']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_mixed_lines',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $payload = json_encode([
            'id' => 'evt_mixed_lines', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_mixed_lines', 'object' => 'invoice', 'customer' => 'cus_mixed_lines',
                'amount_paid' => 500, 'currency' => 'usd', 'billing_reason' => 'manual',
                'lines' => ['object' => 'list', 'data' => [
                    // Non-matching line first (no `parent` at all) -> must be skipped via `continue`.
                    ['id' => 'il_0', 'object' => 'line_item'],
                    [
                        'id' => 'il_1', 'object' => 'line_item',
                        'parent' => [
                            'type' => 'subscription_item_details',
                            'subscription_item_details' => ['subscription' => 'sub_mixed_lines', 'subscription_item' => 'si_1'],
                        ],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('payments', ['payment_invoice_id' => 'in_mixed_lines', 'app_user_id' => $user->id]);
    }

    // ──────────────────────────────────────────────
    // webhook — invoice.payment_failed
    // ──────────────────────────────────────────────

    public function test_invoice_payment_failed_with_no_local_record_returns_ok(): void
    {
        [$payload, $sig] = StripePayloadBuilder::invoicePaymentFailed(
            customerId: 'cus_fail_no_local',
            invoiceId: 'in_fail_no_local',
            subscriptionId: 'sub_never_synced_fail',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();
    }

    public function test_invoice_payment_failed_returns_ok_when_past_due_status_row_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_fail_no_status']);
        $plan = $this->makePlan(PlanType::Basic);
        $active = Status::firstOrCreate(['name' => 'active']); // no 'past_due' row

        $sub = UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_fail_no_status',
            'status_id' => $active->id, 'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        [$payload, $sig] = StripePayloadBuilder::invoicePaymentFailed(
            customerId: 'cus_fail_no_status',
            invoiceId: 'in_fail_no_status',
            subscriptionId: 'sub_fail_no_status',
        );

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertSame($active->id, $sub->fresh()->status_id);
    }

    public function test_invoice_payment_failed_returns_ok_when_no_subscription_id_present(): void
    {
        $payload = json_encode([
            'id' => 'evt_fail_no_sub', 'object' => 'event', 'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_fail_no_sub', 'object' => 'invoice', 'customer' => 'cus_whatever',
                'subscription' => null, 'amount_paid' => 0, 'currency' => 'usd',
                'billing_reason' => 'manual', 'lines' => ['object' => 'list', 'data' => []],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();
    }

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
    // webhook — edge-case payload shapes
    // ──────────────────────────────────────────────

    public function test_checkout_session_completed_handles_expanded_customer_object(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_expanded_123']);

        $payload = json_encode([
            'id' => 'evt_expanded', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_expanded_001', 'object' => 'checkout.session',
                'customer' => ['id' => 'cus_expanded_123', 'object' => 'customer'], // expanded, not a plain string
                'client_reference_id' => (string) $user->id,
                'metadata' => ['app_user_id' => (string) $user->id, 'plan_type' => 'basic', 'currency' => 'usd'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('stripe_checkout_logs', [
            'stripe_checkout_session_id' => 'cs_expanded_001',
            'stripe_customer_id' => 'cus_expanded_123',
        ]);
    }

    public function test_subscription_created_accepts_integer_and_string_cancel_at_period_end(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_bool_shapes']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_bool_shapes';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        $payload = json_encode([
            'id' => 'evt_bool_shapes', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_bool_shapes', 'object' => 'subscription', 'customer' => 'cus_bool_shapes',
                'status' => 'active', 'cancel_at_period_end' => 1, // integer, not a real bool
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_bool', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'payment_subscription_id' => 'sub_bool_shapes',
            'cancel_at_period_end' => true,
        ]);
    }

    public function test_subscription_created_treats_null_cancel_at_period_end_as_false(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_bool_null']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_bool_null';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        $payload = json_encode([
            'id' => 'evt_bool_null', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_bool_null', 'object' => 'subscription', 'customer' => 'cus_bool_null',
                'status' => 'active', 'cancel_at_period_end' => null, // explicit null, not omitted
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_bool_null', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'payment_subscription_id' => 'sub_bool_null',
            'cancel_at_period_end' => false,
        ]);
    }

    public function test_subscription_created_accepts_string_cancel_at_period_end(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_bool_string']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_bool_string';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        $payload = json_encode([
            'id' => 'evt_bool_string', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_bool_string', 'object' => 'subscription', 'customer' => 'cus_bool_string',
                'status' => 'active', 'cancel_at_period_end' => 'true', // string, not bool/int/float/null
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_bool_string', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'payment_subscription_id' => 'sub_bool_string',
            'cancel_at_period_end' => true,
        ]);
    }

    public function test_resolves_current_period_end_from_subscription_item_when_root_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_item_period']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_item_period';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();
        $itemPeriodEnd = now()->addMonth()->timestamp;

        $payload = json_encode([
            'id' => 'evt_item_period', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_item_period', 'object' => 'subscription', 'customer' => 'cus_item_period',
                'status' => 'active', 'cancel_at_period_end' => false,
                // No root current_period_end at all.
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_item_period', 'object' => 'subscription_item',
                    'current_period_end' => $itemPeriodEnd,
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_item_period')->firstOrFail();
        $this->assertSame($itemPeriodEnd, $sub->current_period_end->timestamp);
    }

    public function test_resolves_current_period_end_via_stripe_retrieve_when_root_and_item_are_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_api_period']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_api_period';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();
        $apiPeriodEnd = now()->addMonth()->timestamp;

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/subscriptions/sub_api_period' => [
                'id' => 'sub_api_period', 'object' => 'subscription', 'current_period_end' => $apiPeriodEnd,
                'items' => ['object' => 'list', 'data' => []],
            ],
        ]));

        $payload = json_encode([
            'id' => 'evt_api_period', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_api_period', 'object' => 'subscription', 'customer' => 'cus_api_period',
                'status' => 'active', 'cancel_at_period_end' => false,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_api_period', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_api_period')->firstOrFail();
        $this->assertSame($apiPeriodEnd, $sub->current_period_end->timestamp);
    }

    public function test_resolves_current_period_end_from_retrieved_subscription_item_when_api_root_is_missing(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_api_item_period']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_api_item_period';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();
        $apiItemPeriodEnd = now()->addMonth()->timestamp;

        // Fresh retrieve response has no root current_period_end, only on its item.
        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/subscriptions/sub_api_item_period' => [
                'id' => 'sub_api_item_period', 'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_api_item_period', 'object' => 'subscription_item',
                    'current_period_end' => $apiItemPeriodEnd,
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ],
        ]));

        $payload = json_encode([
            'id' => 'evt_api_item_period', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_api_item_period', 'object' => 'subscription', 'customer' => 'cus_api_item_period',
                'status' => 'active', 'cancel_at_period_end' => false,
                // No root current_period_end and no items[].current_period_end on the webhook payload itself.
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_api_item_period', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_api_item_period')->firstOrFail();
        $this->assertSame($apiItemPeriodEnd, $sub->current_period_end->timestamp);
    }

    public function test_current_period_end_stays_null_when_unresolvable_anywhere(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_no_period_anywhere']);
        $this->makePlan(PlanType::Basic);
        $priceId = 'price_no_period_anywhere';
        config(['services.stripe.prices.basic.usd' => $priceId]);
        $this->seedStatuses();

        // No response registered for the retrieve call either -> it throws, is caught, logged.
        ApiRequestor::setHttpClient(new StripeHttpClientFake([]));

        $payload = json_encode([
            'id' => 'evt_no_period', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_no_period_anywhere', 'object' => 'subscription', 'customer' => 'cus_no_period_anywhere',
                'status' => 'active', 'cancel_at_period_end' => false,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_no_period', 'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $sub = UserSubscription::where('payment_subscription_id', 'sub_no_period_anywhere')->firstOrFail();
        $this->assertNull($sub->current_period_end);
    }

    public function test_invoice_paid_resolves_subscription_id_as_an_expanded_object(): void
    {
        $user = $this->makeAppUser();
        $user->update(['payment_customer_id' => 'cus_sid_object']);
        $plan = $this->makePlan(PlanType::Basic);
        $statuses = $this->seedStatuses();

        UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id,
            'payment_subscription_id' => 'sub_sid_object',
            'status_id' => $statuses['active'], 'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $payload = json_encode([
            'id' => 'evt_sid_object', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_sid_object', 'object' => 'invoice', 'customer' => 'cus_sid_object',
                'amount_paid' => 500, 'currency' => 'usd', 'billing_reason' => 'manual',
                'parent' => ['type' => 'subscription_details', 'subscription_details' => [
                    'subscription' => ['id' => 'sub_sid_object', 'object' => 'subscription'], // expanded object, not a string
                ]],
                'lines' => ['object' => 'list', 'data' => []],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => StripePayloadBuilder::sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $this->assertDatabaseHas('payments', ['payment_invoice_id' => 'in_sid_object', 'app_user_id' => $user->id]);
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
