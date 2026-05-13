<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupabaseAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private string $supabaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supabaseUrl = rtrim(config('services.supabase.url'), '/');
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authorization_without_bearer_prefix_returns_401(): void
    {
        $this->getJson('/api/me', ['Authorization' => 'Token some_token'])->assertUnauthorized();
    }

    public function test_supabase_returning_error_returns_401(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response(['message' => 'Invalid token'], 401),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer invalid_token'])->assertUnauthorized();
    }

    public function test_supabase_response_missing_id_returns_401(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response(['email' => 'user@test.com'], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer some_token'])->assertUnauthorized();
    }

    public function test_supabase_response_missing_email_returns_401(): void
    {
        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response(['id' => Str::uuid()->toString()], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer some_token'])->assertUnauthorized();
    }

    public function test_new_user_is_created_and_authenticated(): void
    {
        $userId = Str::uuid()->toString();

        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => 'new@test.com',
                'email_confirmed_at' => null,
            ], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer valid_token'])->assertOk();

        $this->assertDatabaseHas('app_users', ['supabase_user_id' => $userId, 'email' => 'new@test.com']);
    }

    public function test_existing_user_with_unchanged_email_not_saved(): void
    {
        $userId = Str::uuid()->toString();
        $user = AppUser::create(['supabase_user_id' => $userId, 'email' => 'same@test.com']);
        $originalUpdatedAt = $user->updated_at->toIso8601String();

        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => 'same@test.com',
                'email_confirmed_at' => null,
            ], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer valid_token'])->assertOk();

        $this->assertSame($originalUpdatedAt, $user->fresh()->updated_at->toIso8601String());
    }

    public function test_existing_user_changed_email_is_synced(): void
    {
        $userId = Str::uuid()->toString();
        $user = AppUser::create(['supabase_user_id' => $userId, 'email' => 'old@test.com']);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => 'updated@test.com',
                'email_confirmed_at' => null,
            ], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer valid_token'])->assertOk();

        $this->assertSame('updated@test.com', $user->fresh()->email);
    }

    public function test_email_confirmed_at_synced_to_email_verified_at(): void
    {
        $userId = Str::uuid()->toString();
        $user = AppUser::create(['supabase_user_id' => $userId, 'email' => 'verify@test.com', 'email_verified_at' => null]);

        Http::fake([
            $this->supabaseUrl.'/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => 'verify@test.com',
                'email_confirmed_at' => '2026-05-01T00:00:00Z',
            ], 200),
        ]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer valid_token'])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
