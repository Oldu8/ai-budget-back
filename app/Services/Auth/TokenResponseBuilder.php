<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AppUser;

/**
 * Single source of the auth response shape shared by register/login/social/refresh,
 * so the { access_token, refresh_token, user } contract is never duplicated.
 */
final class TokenResponseBuilder
{
    public function __construct(
        private readonly JwtTokenIssuer $jwt,
        private readonly RefreshTokenStore $refreshTokens,
    ) {}

    /**
     * Fresh login/register/social: issues a brand-new refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: AppUser}
     */
    public function forUser(AppUser $user): array
    {
        return $this->assemble($user, $this->refreshTokens->issue($user));
    }

    /**
     * Refresh flow: reuse the already-rotated refresh token instead of issuing another.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: AppUser}
     */
    public function withRefreshToken(AppUser $user, string $refreshToken): array
    {
        return $this->assemble($user, $refreshToken);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, user: AppUser} */
    private function assemble(AppUser $user, string $refreshToken): array
    {
        $user->loadMissing('settings');

        return [
            'access_token' => $this->jwt->issue($user),
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwt->ttl(),
            'user' => $user,
        ];
    }
}
