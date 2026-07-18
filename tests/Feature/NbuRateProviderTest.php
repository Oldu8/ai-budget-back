<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Currency\Providers\NbuRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NbuRateProviderTest extends TestCase
{
    private function provider(): NbuRateProvider
    {
        return app(NbuRateProvider::class);
    }

    public function test_units_per_eur_renormalizes_from_uah_base(): void
    {
        // NBU quotes UAH per 1 unit of currency. EUR: 1 EUR = 45 UAH. MDL: 1 MDL = 2.5 UAH.
        // So MDL units per 1 EUR = 45 / 2.5 = 18.
        Http::fake([
            'bank.gov.ua/*' => Http::response([
                ['cc' => 'EUR', 'rate' => 45.0],
                ['cc' => 'MDL', 'rate' => 2.5],
                ['cc' => 'USD', 'rate' => 40.0],
            ]),
        ]);

        $result = $this->provider()->unitsPerEur(CarbonImmutable::parse('2026-01-02'));

        $this->assertEqualsWithDelta(18.0, $result['MDL'], 0.0001);
        $this->assertArrayNotHasKey('USD', $result, 'USD is not in config(rates.sources.nbu), must be ignored');
    }

    public function test_units_per_eur_returns_empty_when_eur_is_missing(): void
    {
        Http::fake([
            'bank.gov.ua/*' => Http::response([
                ['cc' => 'MDL', 'rate' => 2.5],
            ]),
        ]);

        $result = $this->provider()->unitsPerEur(CarbonImmutable::parse('2026-01-02'));

        $this->assertSame([], $result);
    }

    public function test_units_per_eur_returns_empty_on_weekend_with_no_data(): void
    {
        Http::fake([
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $result = $this->provider()->unitsPerEur(CarbonImmutable::parse('2026-01-03'));

        $this->assertSame([], $result);
    }

    public function test_units_per_eur_skips_a_tracked_currency_missing_from_the_response(): void
    {
        Http::fake([
            'bank.gov.ua/*' => Http::response([
                ['cc' => 'EUR', 'rate' => 45.0],
                // MDL absent entirely.
            ]),
        ]);

        $result = $this->provider()->unitsPerEur(CarbonImmutable::parse('2026-01-02'));

        $this->assertArrayNotHasKey('MDL', $result);
    }

    public function test_units_per_eur_throws_on_http_failure(): void
    {
        Http::fake([
            'bank.gov.ua/*' => Http::response(null, 500),
        ]);

        $this->expectException(RequestException::class);

        $this->provider()->unitsPerEur(CarbonImmutable::parse('2026-01-02'));
    }
}
