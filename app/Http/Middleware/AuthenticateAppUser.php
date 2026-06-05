<?php

namespace App\Http\Middleware;

use App\Models\AppUser;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\EnsureDefaultSubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single-token auth: validates our own access JWT and loads the AppUser.
 * No network calls — the JWT is verified locally.
 */
class AuthenticateAppUser
{
    public function __construct(
        private readonly JwtTokenIssuer $jwt,
        private readonly EnsureDefaultSubscriptionService $ensureDefaultSubscription,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $appUserId = $this->jwt->appUserIdFromToken(substr($authHeader, 7));

        if ($appUserId === null) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        // The access JWT is stateless and cannot be revoked before it expires, so a token
        // issued before account deletion stays well-formed — reject if the user is gone.
        $user = AppUser::find($appUserId);

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        Auth::setUser($user);

        // Self-healing: provision free plan on first request after period expires.
        // The service is idempotent and protected by a transaction + row lock.
        if ($user->activeSubscription() === null) {
            $this->ensureDefaultSubscription->ensureFreeSubscription($user);
        }

        return $next($request);
    }
}
