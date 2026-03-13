<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Require X-API-Key header when API_SECRET is set. Skips check if empty. */
class EnsureApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $secret = config('services.api_secret');

        if (empty($secret)) {
            return $next($request);
        }

        $key = $request->header('X-API-Key');

        if ($key !== $secret) {
            return response()->json(['message' => 'Invalid or missing API key'], 401);
        }

        return $next($request);
    }
}
