<?php

declare(strict_types=1);

namespace App\Services\Currency;

/**
 * Outcome of a rate sync run, for command output and logging.
 *
 * @param  list<string>  $warnings
 */
final readonly class RateSyncSummary
{
    public function __construct(
        public int $datesProcessed,
        public int $pairsUpserted,
        public array $warnings = [],
    ) {}
}
