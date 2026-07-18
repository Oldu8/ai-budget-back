<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\Currency\CurrencyFallbackResolver;
use App\Services\Currency\Exceptions\UnsupportedCurrencyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CurrencyFallbackResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CurrencyFallbackResolver
    {
        return app(CurrencyFallbackResolver::class);
    }

    private function fakeEcbNearest(array $rates, string $date = '2026-01-02'): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => [$date => $rates]]),
        ]);
    }

    public function test_eur_to_eur_is_identity_on_both_legs(): void
    {
        Http::fake(); // must not call any provider at all
        Http::preventStrayRequests();

        $result = $this->resolver()->resolve(1000, 'EUR', 'EUR', '2026-01-02');

        $this->assertSame(1000, $result->amount);
        $this->assertSame(1.0, $result->rate);
        $this->assertSame('identity', $result->rateSource);
        $this->assertFalse($result->approximate);
    }

    public function test_prices_via_ecb_nearest_for_both_legs(): void
    {
        $this->fakeEcbNearest(['USD' => 1.1, 'GBP' => 0.85]);

        $result = $this->resolver()->resolve(1000, 'USD', 'GBP', '2026-01-02');

        // (EUR per USD) * (GBP per EUR) = (1/1.1) * 0.85
        $this->assertEqualsWithDelta((1 / 1.1) * 0.85, $result->rate, 0.0001);
        $this->assertSame('ecb', $result->rateSource);
        $this->assertFalse($result->approximate);
    }

    public function test_prefers_stored_matrix_for_the_to_leg_over_ecb_nearest(): void
    {
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'UAH', 'date' => '2026-01-01', 'rate' => 45.0, 'source' => 'nbu',
        ]);
        $this->fakeEcbNearest(['USD' => 1.1]); // UAH intentionally absent from ECB

        $result = $this->resolver()->resolve(1000, 'USD', 'UAH', '2026-01-02');

        $this->assertStringContainsString('nbu', $result->rateSource);
        $this->assertFalse($result->approximate);
    }

    public function test_falls_back_to_live_rate_and_flags_approximate_when_ecb_has_no_data(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['ARS' => 1000.0]]),
        ]);

        $result = $this->resolver()->resolve(1000, 'EUR', 'ARS', '2026-01-02');

        $this->assertTrue($result->approximate);
        $this->assertSame('live', $result->rateSource);
    }

    public function test_treats_ecb_nearest_network_failure_as_no_data_and_falls_through_to_live(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(null, 500),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['ARS' => 1000.0]]),
        ]);

        $result = $this->resolver()->resolve(1000, 'EUR', 'ARS', '2026-01-02');

        $this->assertTrue($result->approximate);
        $this->assertSame('live', $result->rateSource);
    }

    public function test_falls_back_to_live_rate_for_the_from_leg(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]), // ECB has nothing for the from-currency
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['ARS' => 1000.0]]),
        ]);

        $result = $this->resolver()->resolve(1000, 'ARS', 'EUR', '2026-01-02');

        $this->assertTrue($result->approximate);
        $this->assertSame('live', $result->rateSource);
    }

    public function test_throws_when_no_source_prices_the_currency(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
            'open.er-api.com/*' => Http::response(['result' => 'error']),
        ]);

        $this->expectException(UnsupportedCurrencyException::class);

        $this->resolver()->resolve(1000, 'EUR', 'ZZZ', '2026-01-02');
    }

    public function test_throws_when_the_from_currency_itself_is_unsupported(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
            'open.er-api.com/*' => Http::response(['result' => 'error']),
        ]);

        $this->expectException(UnsupportedCurrencyException::class);

        $this->resolver()->resolve(1000, 'ZZZ', 'EUR', '2026-01-02');
    }

    public function test_live_rate_fetch_failure_is_swallowed_and_still_throws_unsupported(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
            'open.er-api.com/*' => Http::response(null, 500),
        ]);

        $this->expectException(UnsupportedCurrencyException::class);

        $this->resolver()->resolve(1000, 'EUR', 'ZZZ', '2026-01-02');
    }

    public function test_merges_sources_from_both_legs(): void
    {
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'UAH', 'date' => '2026-01-01', 'rate' => 45.0, 'source' => 'nbu',
        ]);
        $this->fakeEcbNearest(['USD' => 1.1]);

        $result = $this->resolver()->resolve(1000, 'USD', 'UAH', '2026-01-02');

        // from-leg priced via ECB, to-leg via the stored NBU pair.
        $this->assertSame('ecb+nbu', $result->rateSource);
    }
}
