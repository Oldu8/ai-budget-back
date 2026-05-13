<?php

namespace App\Http\Middleware;

use App\Services\ErrorNotifier;
use Closure;
use Illuminate\Http\Request;

/** Require X-API-Key header. In production the key must be set; absence is a misconfiguration. */
class EnsureApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $secret = config('services.api_secret');

        if (empty($secret)) {
            if (app()->isProduction()) {
                ErrorNotifier::notify('Misconfiguration: API_SECRET not set', 'EnsureApiKey middleware received a request but API_SECRET is empty in production.', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ]);

                abort(500, 'API_SECRET is not configured');
            }

            return $next($request);
        }

        $key = $request->header('X-API-Key');

        if ($key !== $secret) {
            return response()->json(['message' => 'Invalid or missing API key'], 401);
        }

        return $next($request);
    }
}
