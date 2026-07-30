<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Currency\RateSyncService;
use App\Services\ErrorNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily exchange-rate sync. Pulls a short recent window (not just today) so a
 * missed run, downtime, or weekend gap self-heals on the next pass; upserts make
 * the overlap harmless.
 */
class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'rates:sync {--days=4 : How many days back to refresh}';

    protected $description = 'Sync recent exchange-rate cross pairs from ECB and NBU';

    public function handle(RateSyncService $sync): int
    {
        $days = max(1, (int) $this->option('days'));
        $to = CarbonImmutable::today();
        $from = $to->subDays($days - 1);

        $summary = $sync->sync($from, $to);

        $this->info("Synced {$summary->datesProcessed} day(s), {$summary->pairsUpserted} pair(s).");
        foreach ($summary->warnings as $warning) {
            $this->warn($warning);
        }

        if ($summary->warnings !== []) {
            ErrorNotifier::notify(
                'rates:sync — partial failure',
                implode("\n", $summary->warnings),
                ['dates_processed' => $summary->datesProcessed, 'pairs_upserted' => $summary->pairsUpserted],
            );
        }

        // Nothing synced at all (e.g. ECB unreachable) — surface a failed run for
        // monitoring. Tomorrow's run still self-heals via the catch-up window.
        if ($summary->datesProcessed === 0 && $summary->warnings !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
