<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUserSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppUserControllerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_destroy_soft_deletes_user(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->deleteJson('/api/me');

        $response->assertOk()
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

    public function test_update_settings_changes_currency(): void
    {
        $user = $this->makeAppUser();
        AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => 'USD',
            'language' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->apiAs($user)->putJson('/api/me/settings', [
            'currency' => 'EUR',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['currency' => 'EUR']);

        $this->assertDatabaseHas('app_user_settings', [
            'app_user_id' => $user->id,
            'currency' => 'EUR',
        ]);
    }
}
