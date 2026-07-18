<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialIdentityVerifier;
use Throwable;

/** Returns a canned identity (or throws a canned exception) instead of verifying a real id_token. */
final class FakeSocialIdentityVerifier implements SocialIdentityVerifier
{
    public function __construct(private readonly SocialIdentity|Throwable $result) {}

    public function verify(string $idToken): SocialIdentity
    {
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
