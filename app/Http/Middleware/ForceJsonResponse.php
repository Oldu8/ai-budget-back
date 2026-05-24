<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Accept') || $request->headers->get('Accept') === '*/*') {
            Log::debug('ForceJsonResponse: injecting Accept: application/json', [
                'path' => $request->path(),
                'method' => $request->method(),
                'original_accept' => $request->headers->get('Accept'),
            ]);

            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
