<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

final class GoogleIdentityVerifier extends AbstractJwksVerifier
{
    protected function provider(): string
    {
        return 'google';
    }

    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function allowedIssuers(): array
    {
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    protected function allowedAudiences(): array
    {
        return config('services.google.audiences', []);
    }

    protected function toIdentity(object $claims): SocialIdentity
    {
        $emailVerified = filter_var($claims->email_verified ?? false, FILTER_VALIDATE_BOOLEAN);

        return new SocialIdentity(
            provider: 'google',
            providerUserId: (string) $claims->sub,
            email: isset($claims->email) ? (string) $claims->email : null,
            emailVerified: $emailVerified,
            name: isset($claims->name) ? (string) $claims->name : null,
        );
    }
}
