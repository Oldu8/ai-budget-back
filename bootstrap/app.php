<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'auth.app' => \App\Http\Middleware\AuthenticateAppUser::class,
            'feature.access' => \App\Http\Middleware\EnsureFeatureAccess::class,
            'api.key' => \App\Http\Middleware\EnsureApiKey::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (\Illuminate\Http\Request $request, \Throwable $e) => $request->is('api/*')
        );

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                Log::debug('API: AuthorizationException rendered as JSON 403', [
                    'path' => $request->path(),
                    'message' => $e->getMessage(),
                ]);

                return response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403);
            }
        });
    })->create();
