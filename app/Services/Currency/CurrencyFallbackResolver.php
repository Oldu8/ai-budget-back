<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use App\Services\Currency\Exceptions\UnsupportedCurrencyException;
use App\Services\Currency\Providers\EcbRateProvider;
use App\Services\Currency\Providers\LiveRateProvider;
use Carbon\CarbonImmutable;

/**
 * Prices a conversion when no stored pair exists — the unsupported-currency
 * fallback. Composes via EUR: (EUR per 1 `from`) × (`to` per 1 EUR).
 *
 * Each leg is resolved historical-first:
 *   1. stored matrix / NBU-backed pair (for the supported `to` leg),
 *   2. ECB historical on the nearest business day (covers ~30 currencies),
 *   3. live current rate from a wide API — accurate enough for recent dates,
 *      flagged `approximate`.
 *
 * Nothing is persisted: this is a rare path and the daily matrix stays pure.
 */
final class CurrencyFallbackResolver
{
    public function __construct(
        private EcbRateProvider $ecb,
        private LiveRateProvider $live,
    ) {}

    public function resolve(int $amount, string $from, string $to, string $date): ConversionResult
    {
        $carbon = CarbonImmutable::parse($date);

        $ecbNearest = $this->safeEcbNearest($carbon);
        /** @var array<string, float>|null $liveRates Lazily fetched once. */
        $liveRates = null;
        $resolveLive = function () use (&$liveRates): array {
            if ($liveRates === null) {
                try {
                    $liveRates = $this->live->unitsPerEur();
                } catch (\Throwable) {
                    $liveRates = [];
                }
            }

            return $liveRates;
        };

        $fromLeg = $this->eurPerUnit($from, $carbon, $ecbNearest, $resolveLive);
        $toLeg = $this->unitPerEur($to, $carbon, $ecbNearest, $resolveLive);

        $rate = $fromLeg['value'] * $toLeg['value'];

        return new ConversionResult(
            amount: (int) round($amount * $rate),
            currency: $to,
            originalAmount: $amount,
            originalCurrency: $from,
            rate: $rate,
            rateDate: min($fromLeg['date'], $toLeg['date']),
            rateSource: $this->mergeSources($fromLeg['source'], $toLeg['source']),
            approximate: $fromLeg['approximate'] || $toLeg['approximate'],
        );
    }

    /**
     * EUR per 1 unit of $currency.
     *
     * @param  array{date: string, rates: array<string, float>}|null  $ecbNearest
     * @param  callable(): array<string, float>  $resolveLive
     * @return array{value: float, date: string, source: string, approximate: bool}
     */
    private function eurPerUnit(string $currency, CarbonImmutable $date, ?array $ecbNearest, callable $resolveLive): array
    {
        if ($currency === 'EUR') {
            return ['value' => 1.0, 'date' => $date->toDateString(), 'source' => 'identity', 'approximate' => false];
        }

        if ($ecbNearest !== null && ($ecbNearest['rates'][$currency] ?? 0.0) > 0.0) {
            return [
                'value' => 1.0 / $ecbNearest['rates'][$currency],
                'date' => $ecbNearest['date'],
                'source' => 'ecb',
                'approximate' => false,
            ];
        }

        $live = $resolveLive();
        if (($live[$currency] ?? 0.0) > 0.0) {
            return [
                'value' => 1.0 / $live[$currency],
                'date' => CarbonImmutable::today()->toDateString(),
                'source' => 'live',
                'approximate' => true,
            ];
        }

        throw new UnsupportedCurrencyException("Currency {$currency} is not supported.");
    }

    /**
     * Units of $currency per 1 EUR.
     *
     * @param  array{date: string, rates: array<string, float>}|null  $ecbNearest
     * @param  callable(): array<string, float>  $resolveLive
     * @return array{value: float, date: string, source: string, approximate: bool}
     */
    private function unitPerEur(string $currency, CarbonImmutable $date, ?array $ecbNearest, callable $resolveLive): array
    {
        if ($currency === 'EUR') {
            return ['value' => 1.0, 'date' => $date->toDateString(), 'source' => 'identity', 'approximate' => false];
        }

        // Stored matrix (covers supported targets incl. NBU-backed UAH/MDL).
        /** @var ExchangeRate|null $row */
        $row = ExchangeRate::query()
            ->where('from_currency', 'EUR')
            ->where('to_currency', $currency)
            ->where('date', '<=', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        if ($row !== null) {
            return [
                'value' => (float) $row->rate,
                'date' => $row->date->toDateString(),
                'source' => $row->source,
                'approximate' => false,
            ];
        }

        if ($ecbNearest !== null && ($ecbNearest['rates'][$currency] ?? 0.0) > 0.0) {
            return [
                'value' => $ecbNearest['rates'][$currency],
                'date' => $ecbNearest['date'],
                'source' => 'ecb',
                'approximate' => false,
            ];
        }

        $live = $resolveLive();
        if (($live[$currency] ?? 0.0) > 0.0) {
            return [
                'value' => $live[$currency],
                'date' => CarbonImmutable::today()->toDateString(),
                'source' => 'live',
                'approximate' => true,
            ];
        }

        throw new UnsupportedCurrencyException("Currency {$currency} is not supported.");
    }

    /**
     * @return array{date: string, rates: array<string, float>}|null
     */
    private function safeEcbNearest(CarbonImmutable $date): ?array
    {
        try {
            return $this->ecb->nearestUnitsPerEur($date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mergeSources(string $a, string $b): string
    {
        $parts = array_values(array_unique(array_filter(
            [$a, $b],
            static fn (string $s): bool => $s !== 'identity',
        )));

        return $parts === [] ? 'identity' : implode('+', $parts);
    }
}
