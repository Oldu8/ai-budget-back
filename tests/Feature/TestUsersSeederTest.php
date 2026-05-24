<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\AppUserSettings;
use App\Models\Status;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAILS = [
        'test-free@budget.test',
        'test-basic@budget.test',
        'test-plus@budget.test',
        'test-premium@budget.test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makePlan(PlanType::Free);
        $this->makePlan(PlanType::Basic);
        $this->makePlan(PlanType::Plus);
        $this->makePlan(PlanType::Premium);
        Status::firstOrCreate(['name' => 'active']);
        $this->makeCategory();
    }

    public function test_seeds_exactly_four_users(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        foreach (self::EMAILS as $email) {
            $this->assertDatabaseHas('app_users', ['email' => $email, 'deleted_at' => null]);
        }

        $this->assertDatabaseCount('app_users', 4);
    }

    public function test_creates_settings_for_all_users(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        $count = AppUserSettings::whereHas('appUser', fn ($q) => $q->whereIn('email', self::EMAILS))->count();

        $this->assertSame(4, $count);
    }

    public function test_creates_active_subscription_for_each_user(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        foreach (AppUser::whereIn('email', self::EMAILS)->get() as $user) {
            $this->assertNotNull(
                $user->activeSubscription(),
                "User {$user->email} has no active subscription",
            );
        }
    }

    public function test_transaction_counts_match_profiles(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        $expected = [
            'test-free@budget.test' => 15,
            'test-basic@budget.test' => 60,
            'test-plus@budget.test' => 120,
            'test-premium@budget.test' => 200,
        ];

        foreach ($expected as $email => $txCount) {
            $user = AppUser::where('email', $email)->firstOrFail();
            $actual = $user->transactions()->whereNotNull('confirmed_at')->count();
            $this->assertSame($txCount, $actual, "Wrong transaction count for {$email}");
        }
    }

    public function test_all_transactions_are_expenses(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        $nonExpenseCount = DB::table('transactions')
            ->join('app_users', 'app_users.id', '=', 'transactions.app_user_id')
            ->whereIn('app_users.email', self::EMAILS)
            ->where('transactions.type', '!=', TransactionType::Expense->value)
            ->whereNull('transactions.deleted_at')
            ->count();

        $this->assertSame(0, $nonExpenseCount);
    }

    public function test_no_duplicate_users_on_rerun(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        $this->assertDatabaseCount('app_users', 4);

        $this->assertSame(
            15,
            AppUser::where('email', 'test-free@budget.test')->firstOrFail()
                ->transactions()->whereNotNull('confirmed_at')->count(),
        );
    }

    public function test_transaction_items_link_to_valid_categories(): void
    {
        $this->artisan('db:seed', ['--class' => 'TestUsersSeeder'])->assertSuccessful();

        $orphans = TransactionItem::whereDoesntHave('category')->count();

        $this->assertSame(0, $orphans, 'Some transaction items have an invalid category_id');
    }
}
