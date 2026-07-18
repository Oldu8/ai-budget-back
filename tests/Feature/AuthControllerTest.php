<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\AppUserIdentity;
use App\Models\Status;
use App\Services\Auth\Social\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialVerifierFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeSocialIdentityVerifier;
use Tests\Support\FakeSocialVerifierFactory;
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
    // social
    // -------------------------------------------------------------------------

    private function bindSocialVerifier(SocialIdentity|\Throwable $result): void
    {
        $this->app->instance(
            SocialVerifierFactory::class,
            new FakeSocialVerifierFactory(new FakeSocialIdentityVerifier($result)),
        );
    }

    private function makeIdentity(array $overrides = []): SocialIdentity
    {
        return new SocialIdentity(
            provider: $overrides['provider'] ?? 'google',
            providerUserId: $overrides['providerUserId'] ?? 'google-user-1',
            email: $overrides['email'] ?? 'social@test.com',
            emailVerified: $overrides['emailVerified'] ?? true,
            // array_key_exists, not ??: a test explicitly passing name => null must keep it null.
            name: array_key_exists('name', $overrides) ? $overrides['name'] : 'Ada Lovelace',
        );
    }

    public function test_social_creates_new_user_and_returns_tokens(): void
    {
        $this->makePlan(PlanType::Free);
        $this->bindSocialVerifier($this->makeIdentity());

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'user']);

        $user = AppUser::where('email', 'social@test.com')->firstOrFail();
        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Lovelace', $user->last_name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('app_user_identities', [
            'app_user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-1',
        ]);
    }

    public function test_social_reuses_existing_linked_identity(): void
    {
        $this->makePlan(PlanType::Free);
        $user = $this->makeAppUser();
        AppUserIdentity::create([
            'app_user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-1',
        ]);
        $this->bindSocialVerifier($this->makeIdentity(['email' => 'different-email@test.com']));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertOk();

        $this->assertDatabaseCount('app_users', 1);
    }

    public function test_social_links_existing_verified_email_instead_of_duplicating(): void
    {
        $this->makePlan(PlanType::Free);
        $existing = AppUser::create(['email' => 'social@test.com']);
        $this->bindSocialVerifier($this->makeIdentity(['emailVerified' => true]));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertOk();

        $this->assertDatabaseCount('app_users', 1);
        $this->assertDatabaseHas('app_user_identities', [
            'app_user_id' => $existing->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-1',
        ]);
    }

    public function test_social_rejects_unverified_email_colliding_with_existing_account(): void
    {
        $this->makePlan(PlanType::Free);
        AppUser::create(['email' => 'social@test.com']);
        $this->bindSocialVerifier($this->makeIdentity(['emailVerified' => false]));

        // Unverified email must never be trusted to link to an existing account —
        // and creating a second account with the same email would hit the DB's
        // unique constraint, so this must be a clean 409, not a 500.
        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertStatus(409)
            ->assertJson(['message' => 'An account with this email already exists. Please sign in using the method you originally used to create it.']);

        $this->assertDatabaseCount('app_users', 1);
    }

    public function test_social_creates_new_user_when_unverified_email_does_not_collide(): void
    {
        $this->makePlan(PlanType::Free);
        $this->bindSocialVerifier($this->makeIdentity(['emailVerified' => false, 'email' => 'brand-new@test.com']));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertOk();

        $user = AppUser::where('email', 'brand-new@test.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_social_uses_client_supplied_name_when_provider_omits_it(): void
    {
        $this->makePlan(PlanType::Free);
        $this->bindSocialVerifier($this->makeIdentity(['name' => null, 'email' => 'apple-user@test.com']));

        $this->postJson('/api/auth/social', [
            'provider' => 'apple',
            'id_token' => 'fake-id-token',
            'name' => 'Grace Hopper',
        ])->assertOk();

        $user = AppUser::where('email', 'apple-user@test.com')->firstOrFail();
        $this->assertSame('Grace', $user->first_name);
        $this->assertSame('Hopper', $user->last_name);
    }

    public function test_social_does_not_overwrite_existing_name_on_repeat_login(): void
    {
        $this->makePlan(PlanType::Free);
        $user = AppUser::create(['email' => 'named@test.com', 'first_name' => 'Original', 'last_name' => 'Name']);
        AppUserIdentity::create([
            'app_user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-1',
        ]);
        $this->bindSocialVerifier($this->makeIdentity(['name' => 'Different Name']));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('Original', $user->first_name);
        $this->assertSame('Name', $user->last_name);
    }

    public function test_social_creates_user_without_name_when_none_supplied(): void
    {
        $this->makePlan(PlanType::Free);
        $this->bindSocialVerifier($this->makeIdentity(['name' => null, 'email' => 'noname@test.com']));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertOk();

        $user = AppUser::where('email', 'noname@test.com')->firstOrFail();
        $this->assertNull($user->first_name);
        $this->assertNull($user->last_name);
    }

    public function test_social_returns_401_when_token_verification_fails(): void
    {
        $this->bindSocialVerifier(new InvalidSocialTokenException('bad token'));

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-id-token',
        ])->assertUnauthorized()
            ->assertJson(['message' => 'Could not verify your sign-in. Please try again.']);
    }

    public function test_social_validation_requires_provider_and_id_token(): void
    {
        $this->postJson('/api/auth/social', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider', 'id_token']);
    }

    public function test_social_rejects_unsupported_provider(): void
    {
        $this->postJson('/api/auth/social', [
            'provider' => 'facebook',
            'id_token' => 'fake-id-token',
        ])->assertStatus(422)->assertJsonValidationErrorFor('provider');
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
