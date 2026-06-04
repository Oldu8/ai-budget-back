<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

/** Narrow contract: turn a provider id_token into a verified SocialIdentity, or fail. */
interface SocialIdentityVerifier
{
    /** @throws InvalidSocialTokenException */
    public function verify(string $idToken): SocialIdentity;
}
