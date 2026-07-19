<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DiscordNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DiscordNotifierTest extends TestCase
{
    public function test_registration_does_nothing_when_no_webhook_configured(): void
    {
        config(['services.discord.registrations_webhook_url' => null]);
        Http::fake();
        Http::preventStrayRequests();

        DiscordNotifier::registration('New user', 'details');

        Http::assertNothingSent();
    }

    public function test_registration_posts_green_embed_to_the_registrations_webhook(): void
    {
        $url = 'https://discord.example.com/registrations';
        config(['services.discord.registrations_webhook_url' => $url]);
        Http::fake();

        DiscordNotifier::registration('New user registered', 'app_user_id: 42');

        Http::assertSent(function ($request) use ($url) {
            return $request->url() === $url
                && $request['embeds'][0]['title'] === 'New user registered'
                && $request['embeds'][0]['color'] === 0x57F287
                && str_contains($request['embeds'][0]['description'], 'app_user_id: 42');
        });
    }

    public function test_payment_posts_blurple_embed_to_the_payments_webhook(): void
    {
        $url = 'https://discord.example.com/payments';
        config(['services.discord.payments_webhook_url' => $url]);
        Http::fake();

        DiscordNotifier::payment('Subscription started', 'User 1 — Basic');

        Http::assertSent(function ($request) use ($url) {
            return $request->url() === $url
                && $request['embeds'][0]['color'] === 0x5865F2;
        });
    }

    public function test_context_is_appended_as_pretty_json(): void
    {
        $url = 'https://discord.example.com/registrations';
        config(['services.discord.registrations_webhook_url' => $url]);
        Http::fake();

        DiscordNotifier::registration('Title', 'desc', ['app_user_id' => 42]);

        Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], '"app_user_id": 42'));
    }

    public function test_description_is_truncated_past_1900_characters(): void
    {
        $url = 'https://discord.example.com/registrations';
        config(['services.discord.registrations_webhook_url' => $url]);
        Http::fake();

        DiscordNotifier::registration('Title', str_repeat('x', 2000));

        Http::assertSent(function ($request) {
            $description = $request['embeds'][0]['description'];

            return strlen($description) < 2000 && str_ends_with($description, '…');
        });
    }

    public function test_http_failure_is_caught_and_does_not_bubble_up(): void
    {
        config(['services.discord.registrations_webhook_url' => 'https://discord.example.com/registrations']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        DiscordNotifier::registration('Title', 'desc'); // must not throw

        $this->addToAssertionCount(1);
    }
}
