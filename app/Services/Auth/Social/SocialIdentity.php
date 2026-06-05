<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

/** Verified identity extracted from a provider id_token. */
final class SocialIdentity
{
    public function __construct(
        public readonly string $provider,        // google | apple
        public readonly string $providerUserId,  // stable subject (sub)
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {}
}
