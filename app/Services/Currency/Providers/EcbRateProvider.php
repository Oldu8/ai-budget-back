<?php

declare(strict_types=1);

namespace App\Services\Currency\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * ECB reference rates via Frankfurter (api.frankfurter.dev). Returns EUR-based
 * quotes (units of each currency per 1 EUR). One HTTP call covers a whole date
 * range; non-business days are simply absent from the response.
 */
final class EcbRateProvider
{
    private const BASE_URL = 'https://api.frankfurter.dev/v1';

    /**
     * EUR-based rates for every ECB currency we track, per business day in
     * [$from, $to].
     *
     * @return array<string, array<string, float>> [date => [currency => unitsPerEur]]
     */
    public function unitsPerEur(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $symbols = implode(',', config('rates.sources.ecb'));

        // Frankfurter uses a single-date endpoint vs a "start..end" range one.
        $path = $from->isSameDay($to)
            ? $from->toDateString()
            : $from->toDateString().'..'.$to->toDateString();

        // Longer timeout + backoff than the request-time lookup below: this runs
        // from a cron job, not a user-facing request, and Frankfurter is occasionally slow.
        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->get(self::BASE_URL.'/'.$path, [
                'base' => 'EUR',
                'symbols' => $symbols,
            ])
            ->throw()
            ->json();

        // Single-date payload: { date, rates: {CCY: x} }.
        // Range payload:       { rates: { date: {CCY: x} } }.
        $rates = $response['rates'] ?? [];

        if ($from->isSameDay($to)) {
            $date = $response['date'] ?? $from->toDateString();

            return $rates === [] ? [] : [$date => $this->floatMap($rates)];
        }

        $byDate = [];
        foreach ($rates as $date => $map) {
            $byDate[$date] = $this->floatMap($map);
        }

        return $byDate;
    }

    /**
     * EUR-based rates for ALL ECB currencies (not just our tracked set) on the
     * nearest business day on or before $date. Used by the unsupported-currency
     * fallback to price a currency we don't sync. Returns [] if none found.
     *
     * @return array{date: string, rates: array<string, float>}|null
     */
    public function nearestUnitsPerEur(CarbonImmutable $date, int $window = 7): ?array
    {
        $from = $date->subDays($window);

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->get(self::BASE_URL.'/'.$from->toDateString().'..'.$date->toDateString(), [
                'base' => 'EUR',
            ])
            ->throw()
            ->json();

        $byDate = $response['rates'] ?? [];
        if ($byDate === []) {
            return null;
        }

        // Latest published day within the window (keys are ISO dates, sortable).
        $latest = max(array_keys($byDate));

        return ['date' => $latest, 'rates' => $this->floatMap($byDate[$latest])];
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, float>
     */
    private function floatMap(array $map): array
    {
        return array_map(static fn ($v): float => (float) $v, $map);
    }
}
