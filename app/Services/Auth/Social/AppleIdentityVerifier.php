<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

final class AppleIdentityVerifier extends AbstractJwksVerifier
{
    protected function provider(): string
    {
        return 'apple';
    }

    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function allowedIssuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function allowedAudiences(): array
    {
        return config('services.apple.audiences', []);
    }

    protected function toIdentity(object $claims): SocialIdentity
    {
        // Apple's email_verified arrives as a bool or the string "true".
        $emailVerified = filter_var($claims->email_verified ?? false, FILTER_VALIDATE_BOOLEAN);

        // Apple never includes the name in the id_token — it's sent separately by the
        // client on first sign-in and is merged in SocialAuthService.
        return new SocialIdentity(
            provider: 'apple',
            providerUserId: (string) $claims->sub,
            email: isset($claims->email) ? (string) $claims->email : null,
            emailVerified: $emailVerified,
            name: null,
        );
    }
}
