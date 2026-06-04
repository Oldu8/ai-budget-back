<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

/** Not final: tests swap this out to fake provider verification (real JWKS isn't reachable in tests). */
class SocialVerifierFactory
{
    public const PROVIDERS = ['google', 'apple'];

    public function __construct(
        private readonly GoogleIdentityVerifier $google,
        private readonly AppleIdentityVerifier $apple,
    ) {}

    public function for(string $provider): SocialIdentityVerifier
    {
        return match ($provider) {
            'google' => $this->google,
            'apple' => $this->apple,
            default => throw new InvalidSocialTokenException("Unsupported provider: {$provider}"),
        };
    }
}
