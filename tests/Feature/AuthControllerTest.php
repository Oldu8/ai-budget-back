<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // register
    // -------------------------------------------------------------------------

    public function test_register_creates_app_user_with_hashed_password_and_tokens(): void
    {
        $this->makePlan(PlanType::Free);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'new@test.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'user']);

        $this->assertDatabaseHas('app_users', ['email' => 'new@test.com']);

        $user = AppUser::where('email', 'new@test.com')->firstOrFail();
        $this->assertNotNull($user->password);
        $this->assertNotSame('password123', $user->password, 'password must be hashed');
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_register_provisions_free_subscription(): void
    {
        $this->makePlan(PlanType::Free);
        Status::firstOrCreate(['name' => 'active']); // EnsureDefaultSubscriptionService needs both

        $this->postJson('/api/auth/register', [
            'email' => 'sub@test.com',
            'password' => 'password123',
        ])->assertCreated();

        $user = AppUser::where('email', 'sub@test.com')->firstOrFail();
        $this->assertDatabaseHas('user_subscriptions', ['app_user_id' => $user->id]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        AppUser::create(['email' => 'dupe@test.com', 'password' => 'password123']);

        $this->postJson('/api/auth/register', [
            'email' => 'dupe@test.com',
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_register_validates_password_length(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'short@test.com',
            'password' => '123',
        ])->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    // -------------------------------------------------------------------------
    // login
    // -------------------------------------------------------------------------

    public function test_login_with_correct_credentials_returns_tokens(): void
    {
        $this->makePlan(PlanType::Free);
        AppUser::create(['email' => 'login@test.com', 'password' => 'password123']);

        $this->postJson('/api/auth/login', [
            'email' => 'login@test.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'user']);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        AppUser::create(['email' => 'login@test.com', 'password' => 'password123']);

        $this->postJson('/api/auth/login', [
            'email' => 'login@test.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_login_unknown_email_returns_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ghost@test.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    public function test_login_social_only_user_without_password_returns_401(): void
    {
        AppUser::create(['email' => 'social@test.com', 'password' => null]);

        $this->postJson('/api/auth/login', [
            'email' => 'social@test.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // refresh / logout
    // -------------------------------------------------------------------------

    public function test_refresh_rotates_token_and_old_one_is_rejected(): void
    {
        $this->makePlan(PlanType::Free);
        $register = $this->postJson('/api/auth/register', [
            'email' => 'refresh@test.com',
            'password' => 'password123',
        ])->assertCreated();

        $oldRefresh = $register->json('refresh_token');

        $rotated = $this->postJson('/api/auth/refresh', ['refresh_token' => $oldRefresh])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token']);

        // Old token no longer works after rotation.
        $this->postJson('/api/auth/refresh', ['refresh_token' => $oldRefresh])->assertUnauthorized();

        // New token works.
        $this->postJson('/api/auth/refresh', ['refresh_token' => $rotated->json('refresh_token')])->assertOk();
    }

    public function test_refresh_with_invalid_token_returns_401(): void
    {
        $this->postJson('/api/auth/refresh', ['refresh_token' => 'nope'])->assertUnauthorized();
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $this->makePlan(PlanType::Free);
        $register = $this->postJson('/api/auth/register', [
            'email' => 'logout@test.com',
            'password' => 'password123',
        ])->assertCreated();

        $refresh = $register->json('refresh_token');

        $this->postJson('/api/auth/logout', ['refresh_token' => $refresh])->assertOk();

        // Revoked token can no longer be refreshed.
        $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
    }
}
