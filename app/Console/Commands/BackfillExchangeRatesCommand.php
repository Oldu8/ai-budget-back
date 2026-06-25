<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Currency\RateSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * One-off historical backfill from `rates.earliest_date` (or --from) to today.
 * ECB returns the whole range in a single call; NBU is looped per day inside
 * the sync service. Idempotent — safe to re-run.
 */
class BackfillExchangeRatesCommand extends Command
{
    protected $signature = 'rates:backfill {--from= : Start date (default config rates.earliest_date)} {--to= : End date (default today)}';

    protected $description = 'Backfill historical exchange-rate cross pairs from ECB and NBU';

    public function handle(RateSyncService $sync): int
    {
        $from = CarbonImmutable::parse(
            $this->option('from') ?? (string) config('rates.earliest_date')
        );
        $to = $this->option('to')
            ? CarbonImmutable::parse($this->option('to'))
            : CarbonImmutable::today();

        if ($from->greaterThan($to)) {
            $this->error("Start date {$from->toDateString()} is after end date {$to->toDateString()}.");

            return self::FAILURE;
        }

        $this->info("Backfilling {$from->toDateString()} .. {$to->toDateString()} (this loops NBU per day)…");

        $summary = $sync->sync($from, $to);

        $this->info("Backfilled {$summary->datesProcessed} day(s), {$summary->pairsUpserted} pair(s).");
        foreach ($summary->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
