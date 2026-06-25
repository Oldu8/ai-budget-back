<?php

declare(strict_types=1);

namespace App\Services\Currency;

/**
 * Outcome of converting an amount between two currencies on a given date.
 *
 * `amount` and `originalAmount` are integers in the smallest currency unit.
 * `rate` satisfies: amount = round(originalAmount * rate).
 *
 * `approximate` is true when at least one leg used a current (not historical)
 * rate — the unsupported-currency fallback for a currency with no free history.
 */
final readonly class ConversionResult
{
    public function __construct(
        public int $amount,
        public string $currency,
        public int $originalAmount,
        public string $originalCurrency,
        public float $rate,
        public string $rateDate,
        public string $rateSource,
        public bool $approximate = false,
    ) {}
}
