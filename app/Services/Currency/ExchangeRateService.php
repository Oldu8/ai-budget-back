<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use App\Services\Currency\Exceptions\RateDateTooEarlyException;
use App\Services\Currency\Exceptions\UnsupportedCurrencyException;
use Carbon\CarbonImmutable;

/**
 * Converts amounts between currencies using historical cross rates stored in
 * `exchange_rates` (one direct pair per lookup). When no stored pair exists —
 * an unsupported source currency, or a gap for a supported one — it delegates
 * to {@see CurrencyFallbackResolver}, which prices it live via EUR.
 */
final class ExchangeRateService
{
    public function __construct(
        private CurrencyFallbackResolver $fallback,
    ) {}

    public function convert(int $amount, string $from, string $to, string $date): ConversionResult
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // The target must be a currency we support (it is the user's base, which
        // settings already constrain). The source may be anything — an unknown
        // `from` is handled by the fallback resolver below.
        $this->guardDate($date);
        $this->guardSupported($to);

        // Same currency: no lookup, rate is 1.
        if ($from === $to) {
            return new ConversionResult(
                amount: $amount,
                currency: $to,
                originalAmount: $amount,
                originalCurrency: $from,
                rate: 1.0,
                rateDate: $date,
                rateSource: 'identity',
            );
        }

        /** @var ExchangeRate|null $row */
        $row = ExchangeRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if ($row === null) {
            // No stored pair (unsupported source, or a gap for a supported one):
            // price it live via EUR, possibly with an approximate leg.
            return $this->fallback->resolve($amount, $from, $to, $date);
        }

        $rate = (float) $row->rate;

        return new ConversionResult(
            amount: (int) round($amount * $rate),
            currency: $to,
            originalAmount: $amount,
            originalCurrency: $from,
            rate: $rate,
            // Date the rate actually came from (exact date, or the nearest
            // earlier business day we hold — rung 2 of the fallback ladder).
            rateDate: $row->date->toDateString(),
            rateSource: $row->source,
        );
    }

    private function guardDate(string $date): void
    {
        $earliest = (string) config('rates.earliest_date');

        if (CarbonImmutable::parse($date)->lt(CarbonImmutable::parse($earliest))) {
            throw new RateDateTooEarlyException(
                "Transactions before {$earliest} are not supported."
            );
        }
    }

    private function guardSupported(string $currency): void
    {
        if (! in_array($currency, $this->supported(), true)) {
            throw new UnsupportedCurrencyException("Currency {$currency} is not supported.");
        }
    }

    /** @return list<string> */
    private function supported(): array
    {
        return config('rates.supported');
    }
}
