<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\AppUserIdentity;
use App\Models\AppUserRefreshToken;
use App\Models\AppUserSettings;
use App\Models\Status;
use App\Models\UserSubscription;
use App\Services\Auth\RefreshTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\StripeHttpClientFake;
use Tests\TestCase;

class AppUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    // ── GET /api/me ───────────────────────────────────────────────────────────

    public function test_show_returns_user_data(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonFragment(['email' => $user->email])
            ->assertJsonStructure(['id', 'email', 'subscription', 'scan_usage']);
    }

    public function test_show_includes_scan_usage_zeros_when_no_subscription(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('scan_usage.scans_remaining', 0)
            ->assertJsonPath('scan_usage.scans_limit', 0)
            ->assertJsonPath('subscription', null);
    }

    public function test_show_includes_subscription_when_subscribed(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus, 50);
        $this->subscribe($user, $plan, usedChecks: 10);

        $response = $this->apiAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('scan_usage.scans_limit', 50)
            ->assertJsonPath('scan_usage.scans_remaining', 40)
            ->assertJsonPath('subscription_status', 'active');
    }

    public function test_show_returns_canceled_within_period_subscription(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus, 50);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_show_001',
            'current_period_end' => now()->addDays(15),
            'cancel_at_period_end' => true,
            'used_checks_count' => 5,
        ]);

        $response = $this->apiAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('subscription_status', 'canceled')
            ->assertJsonPath('subscription.status', 'canceled')
            ->assertJsonPath('scan_usage.scans_limit', 50)
            ->assertJsonPath('scan_usage.scans_remaining', 45);

        $this->assertNotNull($response->json('subscription'));
        $this->assertNotNull($response->json('scan_usage.period_end'));
    }

    public function test_show_returns_null_subscription_for_canceled_past_period_user(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Plus, 50);
        $canceledStatus = Status::firstOrCreate(['name' => 'canceled']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $canceledStatus->id,
            'payment_subscription_id' => 'sub_show_002',
            'current_period_end' => now()->subDay(),
            'cancel_at_period_end' => true,
            'used_checks_count' => 5,
        ]);

        $this->apiAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('subscription_status', null);
    }

    // ── PUT /api/me ───────────────────────────────────────────────────────────

    public function test_update_changes_name(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->putJson('/api/me', [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['first_name' => 'Alice', 'last_name' => 'Smith']);

        $this->assertDatabaseHas('app_users', [
            'id' => $user->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);
    }

    public function test_update_rejects_invalid_name_characters(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->putJson('/api/me', [
            'first_name' => 'Alice123',
        ]);

        $response->assertUnprocessable();
    }

    // ── DELETE /api/me ────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_anonymizes_and_revokes_sessions(): void
    {
        $user = $this->makeAppUser([
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'payment_customer_id' => 'cus_delete_me',
        ]);
        $originalEmail = $user->email;

        AppUserIdentity::create([
            'app_user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-del-1',
        ]);

        $refresh = app(RefreshTokenStore::class);
        $plain = $refresh->issue($user);

        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_delete_me']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([
            '/v1/subscriptions/' => [
                'id' => 'sub_delete_me',
                'object' => 'subscription',
                'status' => 'canceled',
            ],
        ]));

        $response = $this->apiAs($user)->deleteJson('/api/me');

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Account deleted']);

        $this->assertSoftDeleted('app_users', ['id' => $user->id]);

        $deleted = AppUser::withTrashed()->findOrFail($user->id);
        $this->assertSame('deleted+'.$user->id.'@deleted.local', $deleted->email);
        $this->assertNull($deleted->first_name);
        $this->assertNull($deleted->last_name);
        $this->assertNull($deleted->password);
        $this->assertNull($deleted->payment_customer_id);

        $this->assertDatabaseMissing('app_user_identities', ['app_user_id' => $user->id]);
        $this->assertNotNull(
            AppUserRefreshToken::query()
                ->where('app_user_id', $user->id)
                ->whereNotNull('revoked_at')
                ->first()
        );
        $this->assertNull($refresh->rotate($plain));

        $this->makePlan(PlanType::Free);
        Status::firstOrCreate(['name' => 'active']);

        $this->postJson('/api/auth/register', [
            'email' => $originalEmail,
            'password' => 'password123',
        ])->assertCreated();
    }

    public function test_destroy_succeeds_when_stripe_cancel_fails(): void
    {
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Basic);
        $sub = $this->subscribe($user, $plan);
        $sub->update(['payment_subscription_id' => 'sub_cancel_fail']);

        ApiRequestor::setHttpClient(new StripeHttpClientFake([]));

        $this->apiAs($user)->deleteJson('/api/me')
            ->assertOk()
            ->assertJsonFragment(['message' => 'Account deleted']);

        $this->assertSoftDeleted('app_users', ['id' => $user->id]);
    }

    // ── GET /api/me/settings ──────────────────────────────────────────────────

    public function test_show_settings_returns_404_when_not_configured(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/me/settings');

        $response->assertNotFound()
            ->assertJsonFragment(['message' => 'Settings not configured']);
    }

    public function test_show_settings_returns_settings_when_configured(): void
    {
        $user = $this->makeAppUser();
        AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => 'EUR',
            'language' => 'en',
            'timezone' => 'Europe/Berlin',
        ]);

        $response = $this->apiAs($user)->getJson('/api/me/settings');

        $response->assertOk()
            ->assertJsonFragment(['currency' => 'EUR', 'timezone' => 'Europe/Berlin']);
    }

    // ── POST /api/me/settings ─────────────────────────────────────────────────

    public function test_create_settings_stores_new_settings(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->postJson('/api/me/settings', [
            'currency' => 'USD',
            'language' => 'en',
            'timezone' => 'America/New_York',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['currency' => 'USD']);

        $this->assertDatabaseHas('app_user_settings', [
            'app_user_id' => $user->id,
            'currency' => 'USD',
        ]);
    }

    public function test_create_settings_returns_409_when_already_exists(): void
    {
        $user = $this->makeAppUser();
        AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => 'USD',
            'language' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->apiAs($user)->postJson('/api/me/settings', [
            'currency' => 'EUR',
            'language' => 'en',
            'timezone' => 'UTC',
        ]);

        $response->assertStatus(409)
            ->assertJsonFragment(['message' => 'Settings already configured']);
    }

    // ── PUT /api/me/settings ──────────────────────────────────────────────────

    public function test_update_settings_returns_404_when_not_configured(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->putJson('/api/me/settings', [
            'currency' => 'EUR',
        ]);

        $response->assertNotFound();
    }

    public function test_update_settings_locks_currency_but_updates_other_fields(): void
    {
        $user = $this->makeAppUser();
        AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => 'USD',
            'language' => 'en',
            'timezone' => 'UTC',
        ]);

        // Currency is locked once set (it is the conversion target); a change to
        // it is ignored, while other fields still update.
        $response = $this->apiAs($user)->putJson('/api/me/settings', [
            'currency' => 'EUR',
            'language' => 'uk',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['currency' => 'USD'])
            ->assertJsonFragment(['language' => 'uk']);

        $this->assertDatabaseHas('app_user_settings', [
            'app_user_id' => $user->id,
            'currency' => 'USD',
            'language' => 'uk',
        ]);
    }
}
