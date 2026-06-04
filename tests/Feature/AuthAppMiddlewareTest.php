<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\Auth\JwtTokenIssuer;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthAppMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(AppUser $user): string
    {
        return app(JwtTokenIssuer::class)->issue($user);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authorization_without_bearer_prefix_returns_401(): void
    {
        $this->getJson('/api/me', ['Authorization' => 'Token x'])->assertUnauthorized();
    }

    public function test_garbage_token_returns_401(): void
    {
        $this->getJson('/api/me', ['Authorization' => 'Bearer not-a-jwt'])->assertUnauthorized();
    }

    public function test_valid_jwt_authenticates(): void
    {
        $this->makePlan();
        $user = $this->makeAppUser();

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$this->bearer($user)])
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_expired_jwt_returns_401(): void
    {
        $user = $this->makeAppUser();

        $expired = JWT::encode([
            'iss' => config('services.jwt.issuer'),
            'sub' => (string) $user->id,
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ], config('services.jwt.secret'), 'HS256');

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$expired])->assertUnauthorized();
    }

    public function test_token_signed_with_wrong_secret_returns_401(): void
    {
        $user = $this->makeAppUser();

        $forged = JWT::encode([
            'iss' => config('services.jwt.issuer'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'exp' => time() + 3600,
        ], 'a-different-secret-that-is-long-enough-000', 'HS256');

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$forged])->assertUnauthorized();
    }

    public function test_token_for_deleted_user_returns_401(): void
    {
        $this->makePlan();
        $user = $this->makeAppUser();
        $token = $this->bearer($user);

        $user->delete(); // soft delete

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }
}
