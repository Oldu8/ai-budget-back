<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RecurringExpense;
use App\Services\RecurringExpenseSchedulerService;
use Carbon\Carbon;
use Tests\TestCase;

class RecurringExpenseSchedulerServiceTest extends TestCase
{
    private RecurringExpenseSchedulerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecurringExpenseSchedulerService();
    }

    // ── calculateInitialNextRunAt ─────────────────────────────────────────────

    public function test_initial_next_run_at_returns_start_date_when_in_future(): void
    {
        $now = Carbon::parse('2024-01-15');
        $startsAt = Carbon::parse('2024-01-20');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'monthly', 1, $now);

        $this->assertEquals('2024-01-20', $result->toDateString());
    }

    public function test_initial_next_run_at_returns_start_date_when_today(): void
    {
        $now = Carbon::parse('2024-01-15');
        $startsAt = Carbon::parse('2024-01-15');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'monthly', 1, $now);

        $this->assertEquals('2024-01-15', $result->toDateString());
    }

    public function test_initial_next_run_at_advances_weekly_past_start(): void
    {
        $now = Carbon::parse('2024-01-22');
        $startsAt = Carbon::parse('2024-01-01');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'weekly', 1, $now);

        // Jan 1 + 3 weeks = Jan 22, which equals today
        $this->assertEquals('2024-01-22', $result->toDateString());
        $this->assertTrue($result->greaterThanOrEqualTo($now->copy()->startOfDay()));
    }

    public function test_initial_next_run_at_advances_monthly_past_start(): void
    {
        $now = Carbon::parse('2024-03-10');
        $startsAt = Carbon::parse('2024-01-10');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'monthly', 1, $now);

        // Jan 10 → Feb 10 → Mar 10 (equals today)
        $this->assertEquals('2024-03-10', $result->toDateString());
    }

    public function test_initial_next_run_at_advances_monthly_with_interval_2(): void
    {
        $now = Carbon::parse('2024-05-10');
        $startsAt = Carbon::parse('2024-01-10');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'monthly', 2, $now);

        // Jan 10 → Mar 10 → May 10
        $this->assertEquals('2024-05-10', $result->toDateString());
    }

    public function test_initial_next_run_at_advances_yearly_past_start(): void
    {
        $now = Carbon::parse('2025-06-01');
        $startsAt = Carbon::parse('2024-06-01');

        $result = $this->service->calculateInitialNextRunAt($startsAt, 'yearly', 1, $now);

        $this->assertEquals('2025-06-01', $result->toDateString());
    }

    // ── advanceNextRunAt ──────────────────────────────────────────────────────

    public function test_advance_weekly_adds_one_week(): void
    {
        $current = Carbon::parse('2024-01-01');

        $result = $this->service->advanceNextRunAt($current, 'weekly', 1);

        $this->assertEquals('2024-01-08', $result->toDateString());
    }

    public function test_advance_weekly_with_interval_3(): void
    {
        $current = Carbon::parse('2024-01-01');

        $result = $this->service->advanceNextRunAt($current, 'weekly', 3);

        $this->assertEquals('2024-01-22', $result->toDateString());
    }

    public function test_advance_monthly_adds_one_month(): void
    {
        $current = Carbon::parse('2024-01-31');

        $result = $this->service->advanceNextRunAt($current, 'monthly', 1);

        // addMonthsNoOverflow: Jan 31 → Feb 29 (2024 is a leap year)
        $this->assertEquals('2024-02-29', $result->toDateString());
    }

    public function test_advance_yearly_adds_one_year(): void
    {
        $current = Carbon::parse('2024-01-15');

        $result = $this->service->advanceNextRunAt($current, 'yearly', 1);

        $this->assertEquals('2025-01-15', $result->toDateString());
    }

    public function test_advance_unknown_frequency_defaults_to_weekly(): void
    {
        $current = Carbon::parse('2024-01-01');

        $result = $this->service->advanceNextRunAt($current, 'daily', 1);

        $this->assertEquals('2024-01-08', $result->toDateString());
    }

    public function test_advance_does_not_mutate_input(): void
    {
        $current = Carbon::parse('2024-01-01');
        $originalDate = $current->toDateString();

        $this->service->advanceNextRunAt($current, 'monthly', 1);

        $this->assertEquals($originalDate, $current->toDateString());
    }

    // ── normalizeNextRunAtOnEnable ────────────────────────────────────────────

    public function test_normalize_calculates_next_run_at_when_null(): void
    {
        $now = Carbon::parse('2024-03-10');
        $rule = new RecurringExpense([
            'starts_at' => Carbon::parse('2024-01-10'),
            'frequency' => 'monthly',
            'interval' => 1,
            'next_run_at' => null,
        ]);

        $this->service->normalizeNextRunAtOnEnable($rule, $now);

        $this->assertEquals('2024-03-10', $rule->next_run_at->toDateString());
    }

    public function test_normalize_advances_stale_next_run_at(): void
    {
        $now = Carbon::parse('2024-03-10');
        $rule = new RecurringExpense([
            'starts_at' => Carbon::parse('2024-01-10'),
            'frequency' => 'monthly',
            'interval' => 1,
            'next_run_at' => Carbon::parse('2024-01-10'),
        ]);

        $this->service->normalizeNextRunAtOnEnable($rule, $now);

        // Jan 10 → Feb 10 → Mar 10
        $this->assertEquals('2024-03-10', $rule->next_run_at->toDateString());
    }

    public function test_normalize_does_not_change_next_run_at_in_future(): void
    {
        $now = Carbon::parse('2024-03-10');
        $rule = new RecurringExpense([
            'starts_at' => Carbon::parse('2024-01-10'),
            'frequency' => 'monthly',
            'interval' => 1,
            'next_run_at' => Carbon::parse('2024-04-10'),
        ]);

        $this->service->normalizeNextRunAtOnEnable($rule, $now);

        $this->assertEquals('2024-04-10', $rule->next_run_at->toDateString());
    }
}
