<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AppUser;
use App\Models\AppUserRefreshToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opaque refresh tokens stored as sha256 hashes. Tokens rotate on use:
 * the presented token is revoked and a new one is issued.
 */
final class RefreshTokenStore
{
    /** Create a new refresh token for the user and return the plaintext (shown once). */
    public function issue(AppUser $user): string
    {
        $plain = Str::random(64);

        AppUserRefreshToken::create([
            'app_user_id' => $user->id,
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addSeconds($this->ttl()),
        ]);

        return $plain;
    }

    /**
     * Validate a presented refresh token and rotate it atomically.
     * Returns the owning AppUser + a fresh plaintext token, or null if invalid.
     *
     * @return array{user: AppUser, token: string}|null
     */
    public function rotate(string $plain): ?array
    {
        return DB::transaction(function () use ($plain) {
            $record = AppUserRefreshToken::query()
                ->where('token_hash', $this->hash($plain))
                ->lockForUpdate()
                ->first();

            if (! $record || ! $record->isUsable()) {
                return null;
            }

            $user = AppUser::find($record->app_user_id);
            if (! $user) {
                return null;
            }

            $record->update(['revoked_at' => now()]);

            return [
                'user' => $user,
                'token' => $this->issue($user),
            ];
        });
    }

    /** Revoke a single presented refresh token (used on logout). No-op if unknown. */
    public function revoke(string $plain): void
    {
        AppUserRefreshToken::query()
            ->where('token_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** Revoke every active refresh token for a user (e.g. after a password change). */
    public function revokeAllForUser(AppUser $user): void
    {
        AppUserRefreshToken::query()
            ->where('app_user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Revoke all of a user's active refresh tokens except the one presented
     * (keeps the current device signed in while logging out other sessions).
     */
    public function revokeOthersForUser(AppUser $user, ?string $keepPlain): void
    {
        AppUserRefreshToken::query()
            ->where('app_user_id', $user->id)
            ->whereNull('revoked_at')
            ->when(
                $keepPlain !== null && $keepPlain !== '',
                fn ($q) => $q->where('token_hash', '!=', $this->hash($keepPlain)),
            )
            ->update(['revoked_at' => now()]);
    }

    private function ttl(): int
    {
        return (int) config('services.jwt.refresh_ttl', 2592000);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
