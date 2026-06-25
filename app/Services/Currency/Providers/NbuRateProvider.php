<?php

declare(strict_types=1);

namespace App\Services\Currency\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * National Bank of Ukraine open API — for currencies ECB does not publish
 * (UAH, MDL). NBU quotes everything as UAH per 1 unit; we renormalize to
 * EUR-based (units per 1 EUR) so it composes with the ECB matrix.
 *
 * No cheap range endpoint exists, so callers loop per date (one call/day).
 */
final class NbuRateProvider
{
    private const URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange';

    /**
     * EUR-based rates for the NBU currencies we track on $date, or [] if NBU
     * has no data for that day (weekend/holiday) or omits EUR.
     *
     * @return array<string, float> [currency => unitsPerEur]
     */
    public function unitsPerEur(CarbonImmutable $date): array
    {
        // `json` is a valueless flag for NBU; keep it bare via the URL.
        $rows = Http::timeout(15)
            ->retry(2, 500)
            ->get(self::URL.'?date='.$date->format('Ymd').'&json')
            ->throw()
            ->json();

        // UAH per 1 unit of each quoted currency. UAH itself is the NBU base.
        $uahPerUnit = ['UAH' => 1.0];
        foreach ($rows as $row) {
            if (isset($row['cc'], $row['rate'])) {
                $uahPerUnit[$row['cc']] = (float) $row['rate'];
            }
        }

        // Need UAH per EUR to convert NBU's UAH base into an EUR base.
        $uahPerEur = $uahPerUnit['EUR'] ?? null;
        if ($uahPerEur === null || $uahPerEur <= 0.0) {
            return [];
        }

        $result = [];
        foreach (config('rates.sources.nbu') as $currency) {
            $uahPer = $uahPerUnit[$currency] ?? null;
            if ($uahPer === null || $uahPer <= 0.0) {
                continue;
            }
            // unitsOf(currency) per EUR = (UAH per EUR) / (UAH per currency).
            $result[$currency] = $uahPerEur / $uahPer;
        }

        return $result;
    }
}
