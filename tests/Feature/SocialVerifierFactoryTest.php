<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\Social\AppleIdentityVerifier;
use App\Services\Auth\Social\GoogleIdentityVerifier;
use App\Services\Auth\Social\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialVerifierFactory;
use Tests\TestCase;

final class SocialVerifierFactoryTest extends TestCase
{
    public function test_resolves_google_verifier(): void
    {
        $this->assertInstanceOf(
            GoogleIdentityVerifier::class,
            app(SocialVerifierFactory::class)->for('google'),
        );
    }

    public function test_resolves_apple_verifier(): void
    {
        $this->assertInstanceOf(
            AppleIdentityVerifier::class,
            app(SocialVerifierFactory::class)->for('apple'),
        );
    }

    public function test_throws_for_unsupported_provider(): void
    {
        $this->expectException(InvalidSocialTokenException::class);
        $this->expectExceptionMessage('Unsupported provider: facebook');

        app(SocialVerifierFactory::class)->for('facebook');
    }
}
