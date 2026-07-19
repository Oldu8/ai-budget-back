<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedBenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedLeafCategory(): void
    {
        $parent = Category::create(['name' => 'housing', 'label' => 'Housing']);
        Category::create(['name' => 'housing_rent', 'label' => 'Rent', 'category_id' => $parent->id]);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('benchmark:seed')->assertFailed();

        $this->assertDatabaseMissing('app_users', ['email' => 'benchmark@local.test']);
    }

    public function test_creates_main_user_with_transactions_and_items(): void
    {
        $this->seedLeafCategory();

        $this->artisan('benchmark:seed', ['--count' => 20])->assertSuccessful();

        $user = AppUser::where('email', 'benchmark@local.test')->firstOrFail();
        $this->assertSame(20, $user->transactions()->count());
        $this->assertGreaterThan(0, TransactionItem::whereIn(
            'transaction_id',
            $user->transactions()->pluck('id'),
        )->count());
    }

    public function test_rerun_clears_previous_transactions_for_the_same_user(): void
    {
        $this->seedLeafCategory();

        $this->artisan('benchmark:seed', ['--count' => 20])->assertSuccessful();
        $this->artisan('benchmark:seed', ['--count' => 5])->assertSuccessful();

        $user = AppUser::where('email', 'benchmark@local.test')->firstOrFail();
        $this->assertSame(5, $user->transactions()->count());
        $this->assertSame(1, AppUser::where('email', 'benchmark@local.test')->count());
    }

    public function test_fresh_option_drops_existing_benchmark_users_first(): void
    {
        $this->seedLeafCategory();
        $this->artisan('benchmark:seed', ['--count' => 5])->assertSuccessful();
        $originalUserId = AppUser::where('email', 'benchmark@local.test')->value('id');

        $this->artisan('benchmark:seed', ['--count' => 5, '--fresh' => true])->assertSuccessful();

        $newUserId = AppUser::where('email', 'benchmark@local.test')->value('id');
        $this->assertNotSame($originalUserId, $newUserId);
    }

    public function test_seeds_background_users(): void
    {
        $this->seedLeafCategory();

        $this->artisan('benchmark:seed', [
            '--count' => 5,
            '--background-users' => 3,
            '--tx-per-bg-user' => 2,
        ])->assertSuccessful();

        $this->assertSame(3, AppUser::where('email', 'like', 'bg-%@bench.local')->count());
        $this->assertSame(6, Transaction::whereHas(
            'appUser',
            fn ($q) => $q->where('email', 'like', 'bg-%@bench.local'),
        )->count());
    }
}
