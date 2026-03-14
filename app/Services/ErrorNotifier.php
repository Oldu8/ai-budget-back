<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Sends critical errors to Discord/Slack. Use only for:
 * - 5xx (server errors)
 * - 429 (rate limit — needs attention)
 * - Unexpected failures (DB, invalid API response)
 *
 * Do NOT use for: 4xx client errors (wrong password, user exists, validation).
 */
class ErrorNotifier
{
    /** Only 5xx and 429 are worth notifying — real issues, not user mistakes. */
    public static function isCritical(int $httpStatus): bool
    {
        return $httpStatus >= 500 || $httpStatus === 429;
    }

    public static function notify(string $title, string $message, array $context = []): void
    {
        $discordUrl = config('services.discord.webhook_url');
        $slackUrl = config('services.slack.webhook_url');

        $fullMessage = $message;
        if (!empty($context)) {
            $fullMessage .= "\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        $truncated = strlen($fullMessage) > 1900 ? substr($fullMessage, 0, 1900) . '…' : $fullMessage;

        if ($discordUrl) {
            Http::timeout(5)->post($discordUrl, [
                'embeds' => [[
                    'title' => $title,
                    'description' => $truncated,
                    'color' => 15158332, // red
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);
        }

        if ($slackUrl) {
            Http::timeout(5)->post($slackUrl, [
                'text' => "*{$title}*\n{$truncated}",
            ]);
        }
    }
}
