<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Currency\Providers\EcbRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EcbRateProviderTest extends TestCase
{
    private function provider(): EcbRateProvider
    {
        return app(EcbRateProvider::class);
    }

    public function test_units_per_eur_for_a_single_day(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02*' => Http::response([
                'date' => '2026-01-02',
                'rates' => ['USD' => 1.05, 'PLN' => 4.2],
            ]),
        ]);

        $result = $this->provider()->unitsPerEur(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-02'),
        );

        $this->assertSame(['2026-01-02' => ['USD' => 1.05, 'PLN' => 4.2]], $result);
    }

    public function test_units_per_eur_returns_empty_when_no_rates_for_single_day(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-03*' => Http::response(['date' => '2026-01-03', 'rates' => []]),
        ]);

        $result = $this->provider()->unitsPerEur(
            CarbonImmutable::parse('2026-01-03'),
            CarbonImmutable::parse('2026-01-03'),
        );

        $this->assertSame([], $result);
    }

    public function test_units_per_eur_for_a_date_range(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02..2026-01-03*' => Http::response([
                'rates' => [
                    '2026-01-02' => ['USD' => 1.05],
                    '2026-01-03' => ['USD' => 1.06],
                ],
            ]),
        ]);

        $result = $this->provider()->unitsPerEur(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-03'),
        );

        $this->assertSame([
            '2026-01-02' => ['USD' => 1.05],
            '2026-01-03' => ['USD' => 1.06],
        ], $result);
    }

    public function test_units_per_eur_throws_on_http_failure(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(null, 500),
        ]);

        $this->expectException(RequestException::class);

        $this->provider()->unitsPerEur(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-02'),
        );
    }

    public function test_nearest_units_per_eur_returns_latest_day_in_window(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2025-12-27..2026-01-03*' => Http::response([
                'rates' => [
                    '2025-12-30' => ['USD' => 1.04],
                    '2026-01-02' => ['USD' => 1.05], // latest business day <= target
                ],
            ]),
        ]);

        $result = $this->provider()->nearestUnitsPerEur(CarbonImmutable::parse('2026-01-03'));

        $this->assertSame(['date' => '2026-01-02', 'rates' => ['USD' => 1.05]], $result);
    }

    public function test_nearest_units_per_eur_returns_null_when_window_is_empty(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
        ]);

        $result = $this->provider()->nearestUnitsPerEur(CarbonImmutable::parse('2026-01-03'));

        $this->assertNull($result);
    }
}
