<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\JwtTokenIssuer;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class JwtTokenIssuerTest extends TestCase
{
    use RefreshDatabase;

    private function issuer(): JwtTokenIssuer
    {
        return app(JwtTokenIssuer::class);
    }

    public function test_issue_and_verify_round_trip(): void
    {
        $user = $this->makeAppUser();

        $token = $this->issuer()->issue($user);

        $this->assertSame($user->id, $this->issuer()->appUserIdFromToken($token));
    }

    public function test_returns_null_for_malformed_token(): void
    {
        $this->assertNull($this->issuer()->appUserIdFromToken('not-a-jwt'));
    }

    public function test_returns_null_when_sub_claim_is_not_scalar(): void
    {
        $secret = config('services.jwt.secret');
        $token = JWT::encode([
            'iss' => (string) config('services.jwt.issuer'),
            'sub' => ['not' => 'a scalar'],
            'iat' => time(),
            'exp' => time() + 900,
        ], $secret, 'HS256');

        $this->assertNull($this->issuer()->appUserIdFromToken($token));
    }

    public function test_returns_null_when_sub_claim_resolves_to_non_positive_id(): void
    {
        $secret = config('services.jwt.secret');
        $token = JWT::encode([
            'iss' => (string) config('services.jwt.issuer'),
            'sub' => '0',
            'iat' => time(),
            'exp' => time() + 900,
        ], $secret, 'HS256');

        $this->assertNull($this->issuer()->appUserIdFromToken($token));
    }

    public function test_issue_throws_when_jwt_secret_is_not_configured(): void
    {
        config(['services.jwt.secret' => null]);
        $user = $this->makeAppUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET is not configured.');

        $this->issuer()->issue($user);
    }
}
