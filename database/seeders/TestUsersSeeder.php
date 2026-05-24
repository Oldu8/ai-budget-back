<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanType;
use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\AppUserSettings;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Status;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UserSubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        Log::info('TestUsersSeeder: starting');

        $this->seedProfile('test-free@budget.test', PlanType::Free, 15, 30, 'USD');
        $this->seedProfile('test-basic@budget.test', PlanType::Basic, 60, 90, 'EUR');
        $this->seedProfile('test-plus@budget.test', PlanType::Plus, 120, 180, 'USD');
        $this->seedProfile('test-premium@budget.test', PlanType::Premium, 200, 365, 'UAH');
    }

    private function seedProfile(
        string $email,
        PlanType $planType,
        int $txCount,
        int $daysBack,
        string $currency,
    ): void {
        if (DB::table('app_users')->where('email', $email)->exists()) {
            Log::info('TestUsersSeeder: user already exists, skipping', ['email' => $email]);

            return;
        }

        $user = AppUser::create([
            'supabase_user_id' => (string) Str::uuid(),
            'email' => $email,
        ]);

        AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => $currency,
            'language' => 'en',
            'timezone' => 'UTC',
        ]);

        $plan = Plan::where('type', $planType)->firstOrFail();
        $status = Status::where('name', 'active')->firstOrFail();

        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $status->id,
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);

        $this->makeTransactions($user, $txCount, $daysBack);

        Log::info('TestUsersSeeder: created user', [
            'email' => $email,
            'plan' => $planType->value,
            'tx_count' => $txCount,
        ]);
    }

    private function makeTransactions(AppUser $user, int $count, int $daysBack): void
    {
        $categories = Category::whereNotNull('category_id')->get();

        if ($categories->isEmpty()) {
            Log::warning('TestUsersSeeder: no leaf categories found, skipping transactions', [
                'email' => $user->email,
            ]);

            return;
        }

        Log::debug('TestUsersSeeder: creating transactions', [
            'email' => $user->email,
            'count' => $count,
        ]);

        for ($i = 0; $i < $count; $i++) {
            $amount = random_int(500, 50000);

            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => $amount,
                'date' => now()->subDays(random_int(0, $daysBack)),
                'type' => TransactionType::Expense,
                'created_by' => TransactionCreatedBy::User,
                'confirmed_at' => now(),
            ]);

            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'category_id' => $categories->random()->id,
                'amount' => $amount,
            ]);
        }
    }
}
