<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BackfillExchangeRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_from_config_earliest_date_to_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10'));
        $earliest = (string) config('rates.earliest_date');
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
        ]);

        $this->artisan('rates:backfill')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), "{$earliest}..2026-01-10"));
    }

    public function test_respects_explicit_from_and_to_options(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
        ]);

        $this->artisan('rates:backfill', ['--from' => '2026-01-01', '--to' => '2026-01-05'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '2026-01-01..2026-01-05'));
    }

    public function test_reports_warnings_from_the_summary(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-01-01', 'rates' => ['USD' => 1.1]]),
            'bank.gov.ua/*' => Http::response(null, 500),
        ]);

        $this->artisan('rates:backfill', ['--from' => '2026-01-01', '--to' => '2026-01-01'])
            ->expectsOutputToContain('NBU failed')
            ->assertSuccessful();
    }

    public function test_fails_when_from_is_after_to(): void
    {
        $this->artisan('rates:backfill', ['--from' => '2026-01-05', '--to' => '2026-01-01'])
            ->expectsOutputToContain('is after end date')
            ->assertFailed();
    }
}
