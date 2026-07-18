<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Auth\Social\SocialIdentityVerifier;
use App\Services\Auth\Social\SocialVerifierFactory;

/** SocialVerifierFactory is left non-final for exactly this: swap in a fake for both providers. */
final class FakeSocialVerifierFactory extends SocialVerifierFactory
{
    public function __construct(private readonly SocialIdentityVerifier $verifier) {}

    public function for(string $provider): SocialIdentityVerifier
    {
        return $this->verifier;
    }
}
