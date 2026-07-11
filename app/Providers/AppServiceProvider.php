<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\PostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register before any connection is resolved.
        // DB::resolverFor() goes through __call → $this->connection(), which creates the connection
        // before the resolver is registered — too late. Calling the static method directly avoids that.
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new PostgresConnection($connection, $database, $prefix, $config);
        });

        // Receipt scanning talks to Gemini, while the default OpenAI facade
        // (voice: Whisper + expense parsing) keeps using OPENAI_API_KEY.
        $this->app->singleton('openai.scan', function () {
            return \OpenAI::factory()
                ->withApiKey((string) config('services.gemini.api_key'))
                ->withBaseUri((string) config('services.gemini.base_url'))
                ->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
