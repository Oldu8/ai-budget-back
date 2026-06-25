<?php

declare(strict_types=1);

namespace App\Services\Currency\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Wide-coverage CURRENT rates (open.er-api.com, ~160 currencies, no key). The
 * free tier has no history, so this only powers the approximate fallback for a
 * currency that no free historical source (ECB/NBU) covers.
 */
final class LiveRateProvider
{
    private const URL = 'https://open.er-api.com/v6/latest/EUR';

    /**
     * Current units of each currency per 1 EUR (includes EUR => 1.0), or [] on
     * failure.
     *
     * @return array<string, float>
     */
    public function unitsPerEur(): array
    {
        $json = Http::timeout(15)
            ->retry(2, 500)
            ->get(self::URL)
            ->throw()
            ->json();

        if (($json['result'] ?? null) !== 'success') {
            return [];
        }

        return array_map(static fn ($v): float => (float) $v, $json['rates'] ?? []);
    }
}
