<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AppUser;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Issues and verifies our own short-lived access JWT (HS256).
 * Only token concerns — no HTTP, no DB.
 */
final class JwtTokenIssuer
{
    private const ALGO = 'HS256';

    public function issue(AppUser $user): string
    {
        $now = time();

        $payload = [
            'iss' => (string) config('services.jwt.issuer'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'exp' => $now + $this->ttl(),
        ];

        return JWT::encode($payload, $this->secret(), self::ALGO);
    }

    /** Seconds until the issued access token expires. */
    public function ttl(): int
    {
        return (int) config('services.jwt.ttl', 900);
    }

    /**
     * Verify signature/exp/iss and return the app_user id from `sub`.
     * Returns null if the token is invalid, expired, or malformed.
     */
    public function appUserIdFromToken(string $token): ?int
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException|\DomainException) {
            return null;
        }

        $sub = $decoded->sub ?? null;
        if (! is_string($sub) && ! is_int($sub)) {
            return null;
        }

        $id = (int) $sub;

        return $id > 0 ? $id : null;
    }

    private function secret(): string
    {
        $secret = config('services.jwt.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
