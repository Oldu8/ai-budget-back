<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\Social\AppleIdentityVerifier;
use App\Services\Auth\Social\InvalidSocialTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeneratesJwks;
use Tests\TestCase;

final class AppleIdentityVerifierTest extends TestCase
{
    use GeneratesJwks;

    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    protected function setUp(): void
    {
        parent::setUp();

        // JWKS are cached by provider name only — flush so a key from a previous
        // test/keypair can't leak in and make signature verification pass or fail wrongly.
        Cache::flush();
    }

    private function verifier(): AppleIdentityVerifier
    {
        return app(AppleIdentityVerifier::class);
    }

    private function fakeJwks(array $jwk): void
    {
        Http::fake([self::JWKS_URL => Http::response(['keys' => [$jwk]])]);
    }

    private function baseClaims(): array
    {
        return [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-aud', // matches APPLE_CLIENT_IDS in .env.testing
            'sub' => 'apple-user-123',
            'email' => 'user@example.com',
            'email_verified' => 'true', // Apple sends this as a string
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

        $this->assertSame('apple', $identity->provider);
        $this->assertSame('apple-user-123', $identity->providerUserId);
        $this->assertSame('user@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
    }

    public function test_never_reads_a_name_claim_even_if_present(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        // Apple never actually sends a name claim, but even if something did, we must ignore it —
        // the real name only ever arrives out-of-band from the client on first sign-in.
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'name' => 'Should Be Ignored',
        ]);

        $identity = $this->verifier()->verify($token);

        $this->assertNull($identity->name);
    }

    public function test_rejects_unexpected_audience(): void
    {
        $key = $this->makeRsaKey('kid-1');
        $this->fakeJwks($key['jwk']);
        $token = $this->signToken($key['private'], 'kid-1', [
            ...$this->baseClaims(),
            'aud' => 'some-other-bundle-id',
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
        $this->expectExceptionMessage('Invalid apple token');

        $this->verifier()->verify($token);
    }

    public function test_throws_without_hitting_network_when_no_audiences_configured(): void
    {
        config(['services.apple.audiences' => []]);
        Http::fake();
        Http::preventStrayRequests();

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('No allowed audiences configured');

        $this->verifier()->verify('irrelevant-token');
    }

    public function test_throws_when_jwks_endpoint_is_unreachable(): void
    {
        Http::fake([self::JWKS_URL => Http::response(null, 500)]);

        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Could not fetch apple JWKS');

        $this->verifier()->verify('irrelevant-token');
    }
}
