<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared id_token verification: fetch provider JWKS (cached), verify signature + exp,
 * then check `aud` (allowed client ids) and `iss`. Subclasses supply provider specifics.
 */
abstract class AbstractJwksVerifier implements SocialIdentityVerifier
{
    /** Leeway for clock skew between us and the provider, seconds. */
    private const LEEWAY = 30;

    /** How long to cache the provider's JWKS, seconds. */
    private const JWKS_TTL = 3600;

    abstract protected function provider(): string;

    abstract protected function jwksUrl(): string;

    /** @return list<string> acceptable `iss` claim values */
    abstract protected function allowedIssuers(): array;

    /** @return list<string> acceptable `aud` client ids */
    abstract protected function allowedAudiences(): array;

    abstract protected function toIdentity(object $claims): SocialIdentity;

    public function verify(string $idToken): SocialIdentity
    {
        $audiences = $this->allowedAudiences();
        if ($audiences === []) {
            throw new InvalidSocialTokenException(
                "No allowed audiences configured for {$this->provider()} sign-in."
            );
        }

        JWT::$leeway = self::LEEWAY;

        try {
            $claims = JWT::decode($idToken, $this->keys());
        } catch (Throwable $e) {
            throw new InvalidSocialTokenException(
                "Invalid {$this->provider()} token: {$e->getMessage()}",
                previous: $e,
            );
        }

        $aud = $claims->aud ?? null;
        if (! in_array($aud, $audiences, true)) {
            throw new InvalidSocialTokenException("Unexpected audience for {$this->provider()} token.");
        }

        $iss = (string) ($claims->iss ?? '');
        if (! in_array($iss, $this->allowedIssuers(), true)) {
            throw new InvalidSocialTokenException("Unexpected issuer for {$this->provider()} token.");
        }

        $sub = $claims->sub ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new InvalidSocialTokenException("Missing subject in {$this->provider()} token.");
        }

        return $this->toIdentity($claims);
    }

    /** @return array<string, \Firebase\JWT\Key> */
    private function keys(): array
    {
        $jwks = Cache::remember(
            "social_jwks:{$this->provider()}",
            self::JWKS_TTL,
            function (): array {
                $response = Http::timeout(5)->get($this->jwksUrl());
                if ($response->failed()) {
                    throw new InvalidSocialTokenException("Could not fetch {$this->provider()} JWKS.");
                }

                return $response->json();
            },
        );

        return JWK::parseKeySet($jwks);
    }
}
