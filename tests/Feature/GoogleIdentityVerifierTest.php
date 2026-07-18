<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\Social\GoogleIdentityVerifier;
use App\Services\Auth\Social\InvalidSocialTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeneratesJwks;
use Tests\TestCase;

final class GoogleIdentityVerifierTest extends TestCase
{
    use GeneratesJwks;

    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    protected function setUp(): void
    {
        parent::setUp();

        // JWKS are cached by provider name only — flush so a key from a previous
        // test/keypair can't leak in and make signature verification pass or fail wrongly.
        Cache::flush();
    }

    private function verifier(): GoogleIdentityVerifier
    {
        return app(GoogleIdentityVerifier::class);
    }

    private function fakeJwks(array $jwk): void
    {
        Http::fake([self::JWKS_URL => Http::response(['keys' => [$jwk]])]);
    }

    private function baseClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-aud', // matches GOOGLE_CLIENT_IDS in .env.testing
            'sub' => 'google-user-123',
            'email' => 'user@example.com',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
        ];
    }

    public function test_verifies_valid_token_and_maps_identity(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', $this->baseClaims());

        $identity = $this->verifier()->verify($token);

        $this->assertSame('google', $identity->provider);
        $this->assertSame('google-user-123', $identity->providerUserId);
        $this->assertSame('user@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Ada Lovelace', $identity->name);
    }

    public function test_treats_string_email_verified_as_boolean(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'email_verified' => 'false',
        ]);

        $identity = $this->verifier()->verify($token);

        $this->assertFalse($identity->emailVerified);
    }

    public function test_rejects_unexpected_audience(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'aud' => 'some-other-client-id',
        ]);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Unexpected audience');

        $this->verifier()->verify($token);
    }

    public function test_rejects_unexpected_issuer(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'iss' => 'https://evil.example.com',
        ]);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Unexpected issuer');

        $this->verifier()->verify($token);
    }

    public function test_rejects_expired_token(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'iat' => now()->subHours(2)->timestamp,
            'exp' => now()->subHour()->timestamp,
        ]);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Invalid google token');

        $this->verifier()->verify($token);
    }

    public function test_rejects_token_signed_by_a_different_key(): void
    {
        $signingKey = $this->makeRsaKey('kid-1');
        $otherKey = $this->makeRsaKey('kid-1'); // same kid, different keypair
        $this->fakeJwks($otherKey['jwk']); // JWKS only exposes the "wrong" public key
        $token = $this->signToken($signingKey['private'], 'kid-1', $this->baseClaims());

        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify($token);
    }

    public function test_rejects_missing_subject(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $claims = $this->baseClaims();
        unset($claims['sub']);
        $token = $this->signToken($key['private'], 'kid-1', $claims);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Missing subject');

        $this->verifier()->verify($token);
    }

    public function test_throws_without_hitting_network_when_no_audiences_configured(): void
    {
        config(['services.google.audiences' => []]);
        Http::fake(); // any request would fail the test via preventStrayRequests below
        Http::preventStrayRequests();

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('No allowed audiences configured');

        $this->verifier()->verify('irrelevant-token');
    }

    public function test_throws_when_jwks_endpoint_is_unreachable(): void
    {
        Http::fake([self::JWKS_URL => Http::response(null, 500)]);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Could not fetch google JWKS');

        $this->verifier()->verify('irrelevant-token');
    }

    public function test_caches_jwks_across_calls(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', $this->baseClaims());

        $this->verifier()->verify($token);
        $this->verifier()->verify($token);

        Http::assertSentCount(1);
    }
}
