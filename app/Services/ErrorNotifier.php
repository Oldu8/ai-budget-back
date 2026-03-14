<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Sends error notifications to Discord or Slack when webhook URL is configured. */
class ErrorNotifier
{
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
