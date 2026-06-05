<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AppUser;
use App\Services\DiscordNotifier;
use Illuminate\Support\Str;

/**
 * Discord #new-users notification for a freshly registered AppUser.
 * Minimal PII unless DISCORD_REGISTRATION_INCLUDE_EMAIL=true.
 */
final class RegistrationNotifier
{
    public function notify(AppUser $appUser): void
    {
        $lines = ['`app_user_id`: '.$appUser->id];

        if (config('services.discord.registration_include_email')) {
            if ($appUser->email) {
                $lines[] = $appUser->email;
            }
        } elseif ($appUser->email && str_contains($appUser->email, '@')) {
            $lines[] = '`domain`: `@'.Str::after($appUser->email, '@').'`';
        }

        DiscordNotifier::registration('New user registered', implode("\n", $lines));
    }
}
