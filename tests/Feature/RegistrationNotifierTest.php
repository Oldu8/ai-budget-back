<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\Auth\RegistrationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RegistrationNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_masks_email_to_domain_only_by_default(): void
    {
        config(['services.discord.registration_include_email' => false, 'services.discord.registrations_webhook_url' => 'https://discord.example.com/registrations']);
        Http::fake();
        $user = AppUser::create(['email' => 'someone@example.com']);

        app(RegistrationNotifier::class)->notify($user);

        Http::assertSent(function ($request) {
            $description = $request['embeds'][0]['description'];

            return ! str_contains($description, 'someone@example.com')
                && str_contains($description, '@example.com');
        });
    }

    public function test_includes_full_email_when_configured(): void
    {
        config(['services.discord.registration_include_email' => true, 'services.discord.registrations_webhook_url' => 'https://discord.example.com/registrations']);
        Http::fake();
        $user = AppUser::create(['email' => 'someone@example.com']);

        app(RegistrationNotifier::class)->notify($user);

        Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], 'someone@example.com'));
    }
}
