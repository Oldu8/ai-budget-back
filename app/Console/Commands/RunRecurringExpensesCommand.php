<?php

namespace App\Console\Commands;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\RecurringExpenseSchedulerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunRecurringExpensesCommand extends Command
{
    protected $signature = 'app:run-recurring-expenses';

    protected $description = 'Create transactions for due recurring expenses';

    public function __construct(
        private RecurringExpenseSchedulerService $scheduler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        RecurringExpense::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now()->addHours(14))
            ->orderBy('next_run_at')
            ->chunkById(100, function ($rules): void {
                foreach ($rules as $rule) {
                    DB::transaction(function () use ($rule): void {
                        $lockedRule = RecurringExpense::whereKey($rule->id)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedRule || !$lockedRule->is_active || $lockedRule->next_run_at === null) {
                            return;
                        }

                        $user = $lockedRule->appUser;
                        if (!$user || !$user->hasRecurringAccess()) {
                            return;
                        }

                        $timezone = $user->settings?->timezone ?? config('app.timezone');
                        $userToday = now($timezone)->startOfDay();

                        $nextDate = $lockedRule->next_run_at->copy()->startOfDay();
                        $endsDate = $lockedRule->ends_at?->copy()->startOfDay();

                        if ($nextDate->greaterThan($userToday)) {
                            return;
                        }

                        if ($endsDate !== null && $nextDate->greaterThan($endsDate)) {
                            return;
                        }

                        $runAt = $nextDate;

                        $alreadyExists = Transaction::query()
                            ->where('recurring_expense_id', $lockedRule->id)
                            ->where('recurring_run_at', $runAt)
                            ->exists();

                        if ($alreadyExists) {
                            $lockedRule->last_run_at = $runAt;
                            $lockedRule->next_run_at = $this->scheduler->advanceNextRunAt($runAt, $lockedRule->frequency, $lockedRule->interval);
                            $lockedRule->save();

                            return;
                        }

                        $transaction = Transaction::create([
                            'app_user_id' => $lockedRule->app_user_id,
                            'total_amount' => $lockedRule->amount,
                            'date' => $runAt->toDateString(),
                            'type' => TransactionType::Expense,
                            'created_by' => TransactionCreatedBy::Recurring,
                            'confirmed_at' => now(),
                            'recurring_expense_id' => $lockedRule->id,
                            'recurring_run_at' => $runAt,
                        ]);

                        TransactionItem::create([
                            'transaction_id' => $transaction->id,
                            'category_id' => $lockedRule->category_id,
                            'amount' => $lockedRule->amount,
                            'label' => $lockedRule->label,
                        ]);

                        $lockedRule->last_run_at = $runAt;
                        $lockedRule->next_run_at = $this->scheduler->advanceNextRunAt($runAt, $lockedRule->frequency, $lockedRule->interval);
                        $lockedRule->save();
                    });
                }
            });

        return self::SUCCESS;
    }
}
