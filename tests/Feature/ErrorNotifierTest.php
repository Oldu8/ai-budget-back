<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ErrorNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ErrorNotifierTest extends TestCase
{
    public function test_is_critical_for_5xx_and_429(): void
    {
        $this->assertTrue(ErrorNotifier::isCritical(500));
        $this->assertTrue(ErrorNotifier::isCritical(503));
        $this->assertTrue(ErrorNotifier::isCritical(429));
        $this->assertFalse(ErrorNotifier::isCritical(404));
        $this->assertFalse(ErrorNotifier::isCritical(422));
        $this->assertFalse(ErrorNotifier::isCritical(200));
    }

    public function test_notify_does_nothing_when_no_webhooks_configured(): void
    {
        config(['services.discord.errors_webhook_url' => null, 'slack.webhook_url' => null]);
        Http::fake();
        Http::preventStrayRequests();

        ErrorNotifier::notify('Title', 'message');

        Http::assertNothingSent();
    }

    public function test_notify_posts_to_discord_when_configured(): void
    {
        $url = 'https://discord.example.com/errors';
        config(['services.discord.errors_webhook_url' => $url, 'services.slack.webhook_url' => null]);
        Http::fake();

        ErrorNotifier::notify('Something broke', 'stack trace here', ['user_id' => 7]);

        Http::assertSent(function ($request) use ($url) {
            return $request->url() === $url
                && $request['embeds'][0]['title'] === 'Something broke'
                && $request['embeds'][0]['color'] === 15158332
                && str_contains($request['embeds'][0]['description'], 'stack trace here')
                && str_contains($request['embeds'][0]['description'], '"user_id": 7');
        });
    }

    public function test_notify_posts_to_slack_when_configured(): void
    {
        $url = 'https://hooks.slack.example.com/errors';
        config(['services.discord.errors_webhook_url' => null, 'services.slack.webhook_url' => $url]);
        Http::fake();

        ErrorNotifier::notify('Something broke', 'details');

        Http::assertSent(fn ($request) => $request->url() === $url && str_contains($request['text'], 'Something broke'));
    }

    public function test_notify_posts_to_both_when_both_configured(): void
    {
        config([
            'services.discord.errors_webhook_url' => 'https://discord.example.com/errors',
            'services.slack.webhook_url' => 'https://hooks.slack.example.com/errors',
        ]);
        Http::fake();

        ErrorNotifier::notify('Boom', 'details');

        Http::assertSentCount(2);
    }

    public function test_notify_truncates_long_messages(): void
    {
        config(['services.discord.errors_webhook_url' => 'https://discord.example.com/errors']);
        Http::fake();

        ErrorNotifier::notify('Title', str_repeat('x', 2000));

        Http::assertSent(function ($request) {
            $description = $request['embeds'][0]['description'];

            return strlen($description) < 2000 && str_ends_with($description, '…');
        });
    }

    public function test_notify_swallows_http_failures(): void
    {
        config(['services.discord.errors_webhook_url' => 'https://discord.example.com/errors']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        ErrorNotifier::notify('Title', 'message'); // must not throw

        $this->addToAssertionCount(1);
    }
}
