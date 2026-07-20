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
use App\Models\RecurringExpense;
use App\Models\Status;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UserSubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Creates/refreshes the demo account handed to the Apple App Review team
 * (App Store Connect → App Review Information → Sign-in required).
 *
 * Email is fixed; password is the same shared TEST_ACCOUNTS_PASSWORD used by
 * TestUsersSeeder — paste email + that password into App Review Information.
 * Re-running (e.g. on every deploy via app:seed-initial) tops up the
 * plan/subscription, but only seeds transactions/recurring expense once so
 * the account doesn't keep growing.
 *
 * Run: php artisan db:seed --class=AppleReviewerSeeder
 */
class AppleReviewerSeeder extends Seeder
{
    private const EMAIL = 'appreview@vtrata.com';

    /** Keep in sync with TestUsersSeeder::DEFAULT_PASSWORD — same shared test/demo password. */
    private const DEFAULT_PASSWORD = 'te_pass_756-#@!';

    public function run(): void
    {
        $email = self::EMAIL;
        $password = env('TEST_ACCOUNTS_PASSWORD', self::DEFAULT_PASSWORD);

        $user = AppUser::updateOrCreate(
            ['email' => $email],
            ['password' => $password],
        );

        AppUserSettings::firstOrCreate(
            ['app_user_id' => $user->id],
            ['currency' => 'USD', 'language' => 'en', 'timezone' => 'UTC'],
        );

        $plan = Plan::where('type', PlanType::Premium)->first();
        $status = Status::where('name', 'active')->first();

        if ($plan && $status) {
            UserSubscription::updateOrCreate(
                ['app_user_id' => $user->id],
                [
                    'plan_id' => $plan->id,
                    'status_id' => $status->id,
                    'current_period_end' => now()->addYear(),
                    'cancel_at_period_end' => false,
                ],
            );
        }

        if (! Transaction::where('app_user_id', $user->id)->exists()) {
            $this->seedTransactions($user);
            $this->seedRecurringExpense($user);
        }

        Log::info('AppleReviewerSeeder: account ready', ['email' => $email]);
        $this->command->info("Apple reviewer account ready: {$email}");
    }

    private function seedTransactions(AppUser $user): void
    {
        $categories = Category::whereNotNull('category_id')->get();

        if ($categories->isEmpty()) {
            return;
        }

        for ($i = 0; $i < 25; $i++) {
            $amount = random_int(500, 30000);

            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => $amount,
                'date' => now()->subDays(random_int(0, 60)),
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

    private function seedRecurringExpense(AppUser $user): void
    {
        $category = Category::whereNotNull('category_id')->first();

        if (! $category) {
            return;
        }

        RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 999,
            'label' => 'Subscription example',
            'starts_at' => now()->subMonth(),
            'frequency' => 'monthly',
            'interval' => 1,
            'is_active' => true,
            'next_run_at' => now()->addMonth(),
        ]);
    }
}
