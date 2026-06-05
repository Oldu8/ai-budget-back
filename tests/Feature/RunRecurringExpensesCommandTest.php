<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\RecurringExpense;
use App\Models\Status;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunRecurringExpensesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_transaction_for_due_recurring_expense(): void
    {
        $user = $this->makePlusUser();
        $category = $this->makeCategory();
        $rule = RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subDays(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => now()->subDay(),
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $this->assertDatabaseHas('transactions', [
            'app_user_id' => $user->id,
            'recurring_expense_id' => $rule->id,
            'total_amount' => 5000,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
        ]);
    }

    public function test_skips_inactive_recurring_expense(): void
    {
        $user = $this->makePlusUser();
        $category = $this->makeCategory();
        RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subDays(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => false,
            'next_run_at' => now()->subDay(),
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $this->assertDatabaseEmpty('transactions');
    }

    public function test_skips_expired_recurring_expense(): void
    {
        $user = $this->makePlusUser();
        $category = $this->makeCategory();
        RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subMonths(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => now()->subDay(),
            'ends_at' => now()->subDays(5),
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $this->assertDatabaseEmpty('transactions');
    }

    public function test_skips_user_without_recurring_expenses_access(): void
    {
        $user = $this->makeFreeUser();
        $category = $this->makeCategory();
        RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subDays(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => now()->subDay(),
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $this->assertDatabaseEmpty('transactions');
    }

    public function test_advances_next_run_at_after_transaction_created(): void
    {
        $user = $this->makePlusUser();
        $category = $this->makeCategory();
        $nextRunAt = Carbon::yesterday()->startOfDay();

        $rule = RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subMonths(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => $nextRunAt,
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $rule->refresh();
        $this->assertTrue($rule->next_run_at->greaterThan($nextRunAt));
        $this->assertEquals($nextRunAt->toDateString(), $rule->last_run_at->toDateString());
    }

    public function test_does_not_duplicate_transaction_for_same_run_date(): void
    {
        $user = $this->makePlusUser();
        $category = $this->makeCategory();
        $nextRunAt = Carbon::yesterday()->startOfDay();

        $rule = RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 5000,
            'label' => 'Netflix',
            'starts_at' => now()->subMonths(2),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => $nextRunAt,
        ]);

        $this->artisan('app:run-recurring-expenses')->assertSuccessful();
        $this->artisan('app:run-recurring-expenses')->assertSuccessful();

        $this->assertDatabaseCount('transactions', 1);
        $rule->refresh();
        // After second run next_run_at should still advance (via the alreadyExists branch)
        $this->assertTrue($rule->next_run_at->greaterThan($nextRunAt));
    }

    private function makePlusUser(): AppUser
    {
        $user = AppUser::create([
            'email' => Str::random(8).'@test.com',
        ]);

        $plan = Plan::create([
            'type' => PlanType::Plus,
            'price' => 999,
            'label' => 'Plus',
            'check_limit' => 50,
        ]);

        $status = Status::firstOrCreate(['name' => 'active']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $status->id,
            'used_checks_count' => 0,
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);

        return $user;
    }

    private function makeFreeUser(): AppUser
    {
        $user = AppUser::create([
            'email' => Str::random(8).'@test.com',
        ]);

        $plan = Plan::create([
            'type' => PlanType::Free,
            'price' => 0,
            'label' => 'Free',
            'check_limit' => 5,
        ]);

        $status = Status::firstOrCreate(['name' => 'active']);

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $status->id,
            'used_checks_count' => 0,
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);

        return $user;
    }
}
