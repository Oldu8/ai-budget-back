<?php

use App\Http\Middleware\AuthenticateAppUser;
use App\Http\Middleware\EnsureApiKey;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            'auth.app' => AuthenticateAppUser::class,
            'feature.access' => EnsureFeatureAccess::class,
            'api.key' => EnsureApiKey::class,
        ]);
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
        $middleware->api(append: [
            EnsureApiKey::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Create transactions for due recurring expenses.
        $schedule->command('app:run-recurring-expenses')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Refresh exchange rates after ECB publishes (~16:00 CET).
        $schedule->command('rates:sync')
            ->dailyAt('17:00')
            ->timezone('Europe/Berlin')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
        );

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                Log::debug('API: AuthorizationException rendered as JSON 403', [
                    'path' => $request->path(),
                    'message' => $e->getMessage(),
                ]);

                return response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403);
            }
        });
    })->create();
