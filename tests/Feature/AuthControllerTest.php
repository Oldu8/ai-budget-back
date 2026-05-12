<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $supabaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supabaseUrl = rtrim(config('services.supabase.url'), '/');
    }

    // -------------------------------------------------------------------------
    // register
    // -------------------------------------------------------------------------

    public function test_register_creates_app_user_and_returns_201(): void
    {
        $userId = Str::uuid()->toString();
        $this->makePlan(\App\Enums\PlanType::Free);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/signup' => Http::response([
                'access_token' => 'tok_access',
                'refresh_token' => 'tok_refresh',
                'user' => ['id' => $userId, 'email' => 'new@test.com', 'email_confirmed_at' => null],
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'new@test.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'refresh_token', 'user']);

        $this->assertDatabaseHas('app_users', ['supabase_user_id' => $userId]);
    }

    public function test_register_syncs_email_verified_at_when_confirmed(): void
    {
        $userId = Str::uuid()->toString();

        Http::fake([
            $this->supabaseUrl.'/auth/v1/signup' => Http::response([
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'user' => ['id' => $userId, 'email' => 'confirmed@test.com', 'email_confirmed_at' => '2026-05-01T00:00:00Z'],
            ], 200),
        ]);

        $this->postJson('/api/auth/register', ['email' => 'confirmed@test.com', 'password' => 'password123']);

        $user = AppUser::where('supabase_user_id', $userId)->first();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_register_returns_supabase_error_message_on_failure(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/signup' => Http::response([
                'code' => 'user_already_exists',
                'message' => 'User already registered',
            ], 422),
        ]);

        $this->postJson('/api/auth/register', ['email' => 'exists@test.com', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This email is already registered. Try logging in.']);
    }

    public function test_register_returns_500_when_supabase_response_missing_user(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/signup' => Http::response(['access_token' => 'tok'], 200),
        ]);

        $this->postJson('/api/auth/register', ['email' => 'test@test.com', 'password' => 'password123'])
            ->assertStatus(500);
    }

    // -------------------------------------------------------------------------
    // login
    // -------------------------------------------------------------------------

    public function test_login_returns_tokens_and_user(): void
    {
        $userId = Str::uuid()->toString();
        AppUser::create(['supabase_user_id' => $userId, 'email' => 'user@test.com']);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response([
                'access_token' => 'new_access',
                'refresh_token' => 'new_refresh',
                'user' => ['id' => $userId, 'email' => 'user@test.com', 'email_confirmed_at' => null],
            ], 200),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'user@test.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'user']);
    }

    public function test_login_returns_error_when_supabase_rejects_credentials(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response(['message' => 'Invalid login credentials'], 400),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'wrong@test.com', 'password' => 'wrongpass'])
            ->assertStatus(400);
    }

    public function test_login_returns_401_when_app_user_not_registered(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response([
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'user' => ['id' => Str::uuid()->toString(), 'email' => 'ghost@test.com'],
            ], 200),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'ghost@test.com', 'password' => 'password123'])
            ->assertUnauthorized()
            ->assertJsonFragment(['message' => 'This email is not registered. Please sign up before signing in.']);
    }

    public function test_login_syncs_changed_email_from_supabase(): void
    {
        $userId = Str::uuid()->toString();
        $appUser = AppUser::create(['supabase_user_id' => $userId, 'email' => 'old@test.com']);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response([
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'user' => ['id' => $userId, 'email' => 'new@test.com', 'email_confirmed_at' => null],
            ], 200),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'new@test.com', 'password' => 'password123']);

        $this->assertSame('new@test.com', $appUser->fresh()->email);
    }

    public function test_login_syncs_email_verified_at(): void
    {
        $userId = Str::uuid()->toString();
        AppUser::create(['supabase_user_id' => $userId, 'email' => 'verify@test.com', 'email_verified_at' => null]);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response([
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'user' => ['id' => $userId, 'email' => 'verify@test.com', 'email_confirmed_at' => '2026-05-01T00:00:00Z'],
            ], 200),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'verify@test.com', 'password' => 'password123']);

        $user = AppUser::where('supabase_user_id', $userId)->first();
        $this->assertNotNull($user->email_verified_at);
    }

    // -------------------------------------------------------------------------
    // refresh
    // -------------------------------------------------------------------------

    public function test_refresh_returns_new_tokens(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response([
                'access_token' => 'refreshed_access',
                'refresh_token' => 'refreshed_refresh',
            ], 200),
        ]);

        $this->postJson('/api/auth/refresh', ['refresh_token' => 'old_token'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_refresh_returns_401_on_invalid_token(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/token*' => Http::response(['message' => 'Invalid refresh token'], 401),
        ]);

        $this->postJson('/api/auth/refresh', ['refresh_token' => 'bad_token'])
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // logout
    // -------------------------------------------------------------------------

    public function test_logout_returns_400_when_no_authorization_header(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(400);
    }

    public function test_logout_returns_200_on_success(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/logout' => Http::response(null, 204),
        ]);

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer valid_token'])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Logged out']);
    }
}
