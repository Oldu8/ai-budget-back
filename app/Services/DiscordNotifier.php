<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Non-error Discord webhooks (registrations, payments). Each URL targets a channel via Discord's webhook UI.
 * Errors stay on {@see ErrorNotifier}.
 */
class DiscordNotifier
{
    /** Green embed — #new-users style */
    public static function registration(string $title, string $description, array $context = []): void
    {
        self::send(
            config('services.discord.registrations_webhook_url'),
            $title,
            $description,
            $context,
            0x57F287,
        );
    }

    /** Blurple embed — #payment-info (new paid sub, cancellation) */
    public static function payment(string $title, string $description, array $context = []): void
    {
        self::send(
            config('services.discord.payments_webhook_url'),
            $title,
            $description,
            $context,
            0x5865F2,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function send(?string $url, string $title, string $description, array $context, int $color): void
    {
        if (empty($url)) {
            return;
        }

        $full = $description;
        if ($context !== []) {
            $full .= "\n\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        $truncated = strlen($full) > 1900 ? substr($full, 0, 1900).'…' : $full;

        try {
            Http::timeout(5)->post($url, [
                'embeds' => [[
                    'title' => $title,
                    'description' => $truncated,
                    'color' => $color,
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Discord webhook failed', ['error' => $e->getMessage()]);
        }
    }
}
