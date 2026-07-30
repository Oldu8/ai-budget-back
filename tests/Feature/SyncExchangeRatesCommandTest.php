<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SyncExchangeRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_default_four_day_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10'));
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => ['2026-01-10' => ['USD' => 1.1]]]),
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $this->artisan('rates:sync')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '2026-01-07..2026-01-10'));
    }

    public function test_respects_days_option(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10'));
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-01-10', 'rates' => ['USD' => 1.1]]),
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $this->artisan('rates:sync', ['--days' => 1])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/v1/2026-01-10?')
            && ! str_contains((string) $request->url(), '..'));
    }

    public function test_reports_warnings_from_the_summary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10'));
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-01-10', 'rates' => ['USD' => 1.1]]),
            'bank.gov.ua/*' => Http::response(null, 500),
        ]);

        $this->artisan('rates:sync', ['--days' => 1])
            ->expectsOutputToContain('NBU failed')
            ->assertSuccessful();
    }

    /**
     * Frankfurter (ECB) is occasionally slow/unreachable from the cron container.
     * A total ECB failure must not crash the command — it should report a warning
     * and fail cleanly so monitoring can see it, instead of an uncaught exception.
     */
    public function test_fails_cleanly_when_ecb_is_unreachable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10'));
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(null, 500),
        ]);

        $this->artisan('rates:sync', ['--days' => 1])
            ->expectsOutputToContain('ECB failed')
            ->assertFailed();
    }
}
