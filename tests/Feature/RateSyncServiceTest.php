<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\Currency\RateSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RateSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RateSyncService
    {
        return app(RateSyncService::class);
    }

    public function test_syncs_a_single_day_and_expands_all_cross_pairs(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02*' => Http::response([
                'date' => '2026-01-02',
                'rates' => ['USD' => 1.1],
            ]),
            'bank.gov.ua/*' => Http::response([
                ['cc' => 'EUR', 'rate' => 45.0],
                ['cc' => 'MDL', 'rate' => 2.5], // -> 18.0 units per EUR
            ]),
        ]);

        $summary = $this->service()->sync(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-02'),
        );

        $this->assertSame(1, $summary->datesProcessed);
        // NBU always includes UAH (its own base, always 1.0) alongside MDL, so the
        // matrix is 4 currencies (EUR, USD, UAH, MDL): 4*4 - 4 = 12 ordered pairs.
        $this->assertSame(12, $summary->pairsUpserted);
        $this->assertSame([], $summary->warnings);

        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'date' => '2026-01-02', 'source' => 'ecb',
        ]);
        $usdToMdl = ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'MDL')->firstOrFail();
        $this->assertSame('ecb+nbu', $usdToMdl->source);
        // 1 USD = (18.0 MDL/EUR) / (1.1 USD/EUR) MDL
        $this->assertEqualsWithDelta(18.0 / 1.1, (float) $usdToMdl->rate, 0.0001);
    }

    public function test_keeps_ecb_pairs_when_nbu_fails(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02*' => Http::response([
                'date' => '2026-01-02',
                'rates' => ['USD' => 1.1],
            ]),
            'bank.gov.ua/*' => Http::response(null, 500),
        ]);

        $summary = $this->service()->sync(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-02'),
        );

        $this->assertSame(1, $summary->datesProcessed);
        $this->assertCount(1, $summary->warnings);
        $this->assertStringContainsString('NBU failed for 2026-01-02', $summary->warnings[0]);
        $this->assertDatabaseHas('exchange_rates', ['from_currency' => 'EUR', 'to_currency' => 'USD']);
        $this->assertDatabaseMissing('exchange_rates', ['to_currency' => 'MDL']);
    }

    public function test_skips_dates_ecb_has_no_data_for(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-03*' => Http::response(['date' => '2026-01-03', 'rates' => []]),
        ]);

        $summary = $this->service()->sync(
            CarbonImmutable::parse('2026-01-03'),
            CarbonImmutable::parse('2026-01-03'),
        );

        $this->assertSame(0, $summary->datesProcessed);
        $this->assertSame(0, $summary->pairsUpserted);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_skips_a_day_whose_ecb_rates_are_empty_within_a_range(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02..2026-01-03*' => Http::response([
                'rates' => [
                    '2026-01-02' => [], // e.g. a holiday quirk in the range payload
                    '2026-01-03' => ['USD' => 1.1],
                ],
            ]),
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $summary = $this->service()->sync(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-03'),
        );

        // Only 2026-01-03 produces rows; 2026-01-02 has nothing to expand (EUR alone).
        $this->assertSame(1, $summary->datesProcessed);
        $this->assertDatabaseMissing('exchange_rates', ['date' => '2026-01-02']);
        $this->assertDatabaseHas('exchange_rates', ['date' => '2026-01-03']);
    }

    public function test_excludes_non_positive_rate_currency_as_from_but_keeps_it_as_to(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02*' => Http::response([
                'date' => '2026-01-02',
                // ZWL comes back at a non-positive rate (e.g. a bad/zeroed quote from the source).
                'rates' => ['USD' => 1.1, 'ZWL' => 0.0],
            ]),
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $summary = $this->service()->sync(
            CarbonImmutable::parse('2026-01-02'),
            CarbonImmutable::parse('2026-01-02'),
        );

        $this->assertSame(1, $summary->datesProcessed);
        // EUR, USD, ZWL: 3*3 - 3 = 6 ordered pairs, minus the 2 pairs with ZWL as `from` -> 4.
        $this->assertSame(4, $summary->pairsUpserted);

        // ZWL never appears as the `from_currency` (its rate is <= 0)...
        $this->assertDatabaseMissing('exchange_rates', ['from_currency' => 'ZWL']);
        // ...but it still appears as a `to_currency`, since only the outer loop guards on it.
        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'EUR', 'to_currency' => 'ZWL', 'date' => '2026-01-02', 'rate' => 0,
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'USD', 'to_currency' => 'ZWL', 'date' => '2026-01-02', 'rate' => 0,
        ]);
    }

    public function test_sync_is_idempotent_on_rerun(): void
    {
        Http::fake([
            'api.frankfurter.dev/v1/2026-01-02*' => Http::response(['date' => '2026-01-02', 'rates' => ['USD' => 1.1]]),
            'bank.gov.ua/*' => Http::response([]),
        ]);

        $this->service()->sync(CarbonImmutable::parse('2026-01-02'), CarbonImmutable::parse('2026-01-02'));
        $this->service()->sync(CarbonImmutable::parse('2026-01-02'), CarbonImmutable::parse('2026-01-02'));

        // EUR<->USD only (NBU empty this time): 2 rows, not 4.
        $this->assertDatabaseCount('exchange_rates', 2);
    }
}
