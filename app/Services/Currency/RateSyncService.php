<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use App\Services\Currency\Providers\EcbRateProvider;
use App\Services\Currency\Providers\NbuRateProvider;
use Carbon\CarbonImmutable;

/**
 * Pulls EUR-based quotes from ECB (one ranged call) and NBU (per day), then
 * expands each day into the full matrix of directed cross pairs and upserts
 * them. Idempotent: re-running a date overwrites its rows. Drives both the
 * daily sync and the one-off backfill.
 */
final class RateSyncService
{
    public function __construct(
        private EcbRateProvider $ecb,
        private NbuRateProvider $nbu,
    ) {}

    public function sync(CarbonImmutable $from, CarbonImmutable $to): RateSyncSummary
    {
        // ECB business days drive which dates exist (one HTTP call for the range).
        // Frankfurter is occasionally slow/unreachable — don't let a transient
        // timeout crash the whole run; the daily catch-up window self-heals it.
        try {
            $ecbByDate = $this->ecb->unitsPerEur($from, $to);
        } catch (\Throwable $e) {
            return new RateSyncSummary(0, 0, ["ECB failed for {$from->toDateString()}..{$to->toDateString()}: {$e->getMessage()}"]);
        }

        $datesProcessed = 0;
        $pairsUpserted = 0;
        $warnings = [];

        foreach ($ecbByDate as $date => $ecbMap) {
            try {
                $nbuMap = $this->nbu->unitsPerEur(CarbonImmutable::parse($date));
            } catch (\Throwable $e) {
                // Partial failure: keep the ECB pairs, note the gap to backfill.
                $nbuMap = [];
                $warnings[] = "NBU failed for {$date}: {$e->getMessage()}";
            }

            // EUR-based vector: units of each currency per 1 EUR. EUR itself is 1.
            $perEur = ['EUR' => 1.0] + $ecbMap + $nbuMap;
            $source = $this->currencySources($ecbMap, $nbuMap);

            $rows = $this->expandPairs($perEur, $source, $date);
            if ($rows === []) {
                continue;
            }

            ExchangeRate::upsert(
                $rows,
                ['from_currency', 'to_currency', 'date'],
                ['rate', 'source', 'updated_at'],
            );

            $datesProcessed++;
            $pairsUpserted += count($rows);
        }

        return new RateSyncSummary($datesProcessed, $pairsUpserted, $warnings);
    }

    /**
     * Every ordered pair (from != to) with rate = unitsOf(to)/unitsOf(from).
     *
     * @param  array<string, float>  $perEur
     * @param  array<string, string>  $source
     * @return list<array<string, mixed>>
     */
    private function expandPairs(array $perEur, array $source, string $date): array
    {
        $now = now();
        $rows = [];

        foreach ($perEur as $from => $fromPerEur) {
            if ($fromPerEur <= 0.0) {
                continue;
            }
            foreach ($perEur as $to => $toPerEur) {
                if ($from === $to) {
                    continue;
                }

                $rows[] = [
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'date' => $date,
                    'rate' => $toPerEur / $fromPerEur,
                    'source' => $this->pairSource($source[$from], $source[$to]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * Origin label per currency: 'ecb', 'nbu', or 'identity' (EUR pivot).
     *
     * @param  array<string, float>  $ecbMap
     * @param  array<string, float>  $nbuMap
     * @return array<string, string>
     */
    private function currencySources(array $ecbMap, array $nbuMap): array
    {
        $source = ['EUR' => 'identity'];
        foreach (array_keys($ecbMap) as $currency) {
            $source[$currency] = 'ecb';
        }
        foreach (array_keys($nbuMap) as $currency) {
            $source[$currency] = 'nbu';
        }

        return $source;
    }

    private function pairSource(string $a, string $b): string
    {
        $parts = array_values(array_unique(array_filter(
            [$a, $b],
            static fn (string $s): bool => $s !== 'identity',
        )));

        return $parts === [] ? 'identity' : implode('+', $parts);
    }
}
