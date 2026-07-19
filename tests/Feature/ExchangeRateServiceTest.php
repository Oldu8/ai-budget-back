<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\Currency\Exceptions\RateDateTooEarlyException;
use App\Services\Currency\Exceptions\UnsupportedCurrencyException;
use App\Services\Currency\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    public function test_same_currency_is_a_no_op(): void
    {
        $result = $this->service()->convert(1000, 'usd', 'USD', '2026-01-02');

        $this->assertSame(1000, $result->amount);
        $this->assertSame(1.0, $result->rate);
        $this->assertSame('identity', $result->rateSource);
    }

    public function test_uses_stored_pair_when_present(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'EUR', 'date' => '2026-01-02', 'rate' => 0.9, 'source' => 'ecb',
        ]);

        $result = $this->service()->convert(1000, 'USD', 'EUR', '2026-01-02');

        $this->assertSame(900, $result->amount);
        $this->assertSame('ecb', $result->rateSource);
        $this->assertSame('2026-01-02', $result->rateDate);
    }

    public function test_uses_nearest_earlier_stored_pair_within_a_gap(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'EUR', 'date' => '2026-01-01', 'rate' => 0.9, 'source' => 'ecb',
        ]);

        $result = $this->service()->convert(1000, 'USD', 'EUR', '2026-01-05');

        $this->assertSame('2026-01-01', $result->rateDate);
    }

    public function test_rejects_dates_before_earliest_supported_date(): void
    {
        $this->expectException(RateDateTooEarlyException::class);

        $this->service()->convert(1000, 'USD', 'EUR', '2020-01-01');
    }

    public function test_rejects_unsupported_target_currency(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        $this->service()->convert(1000, 'USD', 'XYZ', '2026-01-02');
    }

    public function test_falls_back_when_no_stored_pair_exists(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => ['2026-01-02' => ['USD' => 1.1]]]),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.1]]),
        ]);

        $result = $this->service()->convert(1000, 'USD', 'EUR', '2026-01-02');

        $this->assertSame('EUR', $result->currency);
        $this->assertGreaterThan(0, $result->rate);
    }
}
