<?php

namespace App\Services;

use App\Models\RecurringExpense;
use Carbon\Carbon;

class RecurringExpenseSchedulerService
{
    public function calculateInitialNextRunAt(Carbon $startsAt, string $frequency, int $interval, Carbon $now): Carbon
    {
        $next = $startsAt->copy()->startOfDay();
        $today = $now->copy()->startOfDay();

        while ($next->lessThan($today)) {
            $next = $this->advanceNextRunAt($next, $frequency, $interval);
        }

        return $next;
    }

    public function advanceNextRunAt(Carbon $current, string $frequency, int $interval): Carbon
    {
        return match ($frequency) {
            'weekly' => $current->copy()->addWeeks($interval),
            'monthly' => $current->copy()->addMonthsNoOverflow($interval),
            'yearly' => $current->copy()->addYears($interval),
            default => $current->copy()->addWeeks($interval),
        };
    }

    public function normalizeNextRunAtOnEnable(RecurringExpense $rule, Carbon $now): void
    {
        $startsAt = ($rule->starts_at ?? $now)->copy()->startOfDay();
        $interval = (int) $rule->interval ?: 1;

        if ($rule->next_run_at === null) {
            $rule->next_run_at = $this->calculateInitialNextRunAt($startsAt, $rule->frequency, $interval, $now);
            return;
        }

        $next = $rule->next_run_at->copy()->startOfDay();
        $today = $now->copy()->startOfDay();

        while ($next->lessThan($today)) {
            $next = $this->advanceNextRunAt($next, $rule->frequency, $interval);
        }

        $rule->next_run_at = $next;
    }
}

