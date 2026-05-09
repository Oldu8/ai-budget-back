<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\AppUserCategorySetting;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\AnalyticsDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnalyticsDashboardService;
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeParentCategory(string $name = 'Housing'): Category
    {
        return Category::create(['name' => $name, 'label' => $name]);
    }

    private function makeChildCategory(Category $parent, string $name = 'Rent'): Category
    {
        return Category::create([
            'name' => $name,
            'label' => $name,
            'category_id' => $parent->id,
        ]);
    }

    private function makeExpense(
        AppUser $user,
        Category $leaf,
        int $amount,
        string $date,
        bool $confirmed = true,
    ): Transaction {
        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => $amount,
            'date' => $date,
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
        TransactionItem::create([
            'transaction_id' => $tx->id,
            'category_id' => $leaf->id,
            'amount' => $amount,
        ]);

        return $tx;
    }

    private function makeIncome(AppUser $user, Category $leaf, int $amount, string $date): Transaction
    {
        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => $amount,
            'date' => $date,
            'type' => TransactionType::Income,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create([
            'transaction_id' => $tx->id,
            'category_id' => $leaf->id,
            'amount' => $amount,
        ]);

        return $tx;
    }

    // ── basic aggregation ──────────────────────────────────────────────────────

    public function test_returns_zeros_when_no_transactions(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
        $this->assertEquals(0, $result['overview']['previous_month_total']);
        $this->assertEquals(0, $result['overview']['average_per_day']);
        $this->assertNull($result['overview']['biggest_category']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['trends']['categories']);
        $this->assertEmpty($result['trends']['subcategories']);
    }

    public function test_sums_expenses_across_multiple_transactions(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-03-01');
        $this->makeExpense($user, $child, 500, '2024-03-15');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(1500, $result['overview']['total_spent']);
    }

    public function test_ignores_income_transactions(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeIncome($user, $child, 5000, '2024-03-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
        $this->assertEmpty($result['categories']);
    }

    public function test_ignores_unconfirmed_transactions(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 2000, '2024-03-05', confirmed: false);

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
    }

    public function test_ignores_other_users_transactions(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($other, $child, 3000, '2024-03-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
    }

    public function test_ignores_transactions_outside_requested_month(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-02-28'); // previous month
        $this->makeExpense($user, $child, 1000, '2024-04-01'); // next month

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
    }

    public function test_excludes_disabled_categories(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 2000, '2024-03-10');
        AppUserCategorySetting::create([
            'app_user_id' => $user->id,
            'category_id' => $child->id,
            'visibility_tag' => 'hidden',
        ]);

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['total_spent']);
        $this->assertEmpty($result['categories']);
    }

    // ── previous month comparison ──────────────────────────────────────────────

    public function test_computes_diff_against_previous_month(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 3000, '2024-03-10');
        $this->makeExpense($user, $child, 1000, '2024-02-15');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(3000, $result['overview']['total_spent']);
        $this->assertEquals(1000, $result['overview']['previous_month_total']);
        $this->assertEquals(2000, $result['overview']['difference_amount']);
        $this->assertEquals('up', $result['overview']['direction']);
    }

    public function test_direction_down_when_spending_decreased(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-03-10');
        $this->makeExpense($user, $child, 3000, '2024-02-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('down', $result['overview']['direction']);
        $this->assertEquals(-2000, $result['overview']['difference_amount']);
    }

    public function test_direction_flat_when_spending_unchanged(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-03-10');
        $this->makeExpense($user, $child, 1000, '2024-02-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('flat', $result['overview']['direction']);
        $this->assertEquals(0.0, $result['overview']['difference_percent']);
    }

    public function test_difference_percent_null_when_no_previous_spending(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-03-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertNull($result['overview']['difference_percent']);
        $this->assertEquals('up', $result['overview']['direction']);
    }

    // ── derived metrics ────────────────────────────────────────────────────────

    public function test_average_per_day_uses_days_in_month(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        // March has 31 days: 3100 / 31 = 100
        $this->makeExpense($user, $child, 3100, '2024-03-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(100, $result['overview']['average_per_day']);
    }

    public function test_average_per_day_is_zero_when_no_spending(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(0, $result['overview']['average_per_day']);
    }

    public function test_biggest_category_is_highest_spending_parent(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        $this->makeExpense($user, $child1, 5000, '2024-03-01');
        $this->makeExpense($user, $child2, 2000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals($parent1->id, $result['overview']['biggest_category']['id']);
        $this->assertEquals(5000, $result['overview']['biggest_category']['amount']);
    }

    public function test_biggest_category_is_null_when_no_spending(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertNull($result['overview']['biggest_category']);
    }

    // ── categories list ────────────────────────────────────────────────────────

    public function test_categories_sorted_by_amount_desc(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        $this->makeExpense($user, $child1, 1000, '2024-03-01');
        $this->makeExpense($user, $child2, 3000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');
        $amounts = array_column($result['categories'], 'amount');

        $this->assertEquals([3000, 1000], $amounts);
    }

    public function test_category_percent_of_total_is_correct(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        $this->makeExpense($user, $child1, 7500, '2024-03-01');
        $this->makeExpense($user, $child2, 2500, '2024-03-01');

        $result = $this->service->build($user, '2024-03');
        $percents = array_column($result['categories'], 'percent');

        $this->assertEquals(75.0, $percents[0]);
        $this->assertEquals(25.0, $percents[1]);
    }

    public function test_subcategories_nested_under_correct_parent(): void
    {
        $user = $this->makeAppUser();

        $parent = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent, 'Rent');
        $child2 = $this->makeChildCategory($parent, 'Utilities');

        $this->makeExpense($user, $child1, 3000, '2024-03-01');
        $this->makeExpense($user, $child2, 1000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');

        $this->assertCount(1, $result['categories']);
        $subIds = array_column($result['categories'][0]['subcategories'], 'id');
        $this->assertContains($child1->id, $subIds);
        $this->assertContains($child2->id, $subIds);
    }

    // ── trends ─────────────────────────────────────────────────────────────────

    public function test_trends_categories_sorted_by_absolute_change_desc(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        // Housing: change +4000
        $this->makeExpense($user, $child1, 5000, '2024-03-01');
        $this->makeExpense($user, $child1, 1000, '2024-02-01');

        // Food: change +500
        $this->makeExpense($user, $child2, 3000, '2024-03-01');
        $this->makeExpense($user, $child2, 2500, '2024-02-01');

        $result = $this->service->build($user, '2024-03');
        $trends = $result['trends']['categories'];

        $this->assertEquals($parent1->id, $trends[0]['id']);
        $this->assertEquals(4000, $trends[0]['difference_amount']);
    }

    public function test_trends_include_categories_that_disappeared(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory('Housing'), 'Rent');

        // Only previous month, nothing current
        $this->makeExpense($user, $child, 2000, '2024-02-10');

        $result = $this->service->build($user, '2024-03');
        $trends = $result['trends']['categories'];

        $this->assertCount(1, $trends);
        $this->assertEquals(0, $trends[0]['current_amount']);
        $this->assertEquals(2000, $trends[0]['previous_amount']);
        $this->assertEquals('down', $trends[0]['direction']);
    }

    // ── response shape ─────────────────────────────────────────────────────────

    public function test_period_dates_are_correct(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('2024-03-01', $result['period']['from']);
        $this->assertEquals('2024-03-31', $result['period']['to']);
        $this->assertEquals('2024-02-01', $result['period']['previous_from']);
        $this->assertEquals('2024-02-29', $result['period']['previous_to']); // 2024 is a leap year
    }

    public function test_month_field_matches_input(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('2024-03', $result['month']);
    }

    public function test_currency_defaults_to_usd_when_settings_absent(): void
    {
        $user = $this->makeAppUser();

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('USD', $result['currency']);
    }

    public function test_currency_taken_from_user_settings(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, currency: 'eur');
        $user->load('settings');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals('EUR', $result['currency']);
    }

    // ── multi-item transactions ────────────────────────────────────────────────

    public function test_transaction_with_items_in_multiple_subcategories(): void
    {
        $user = $this->makeAppUser();

        $parent = $this->makeParentCategory('Shopping');
        $child1 = $this->makeChildCategory($parent, 'Clothes');
        $child2 = $this->makeChildCategory($parent, 'Electronics');

        // One transaction split across two subcategories
        $tx = \App\Models\Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 3000,
            'date' => '2024-03-10',
            'type' => \App\Enums\TransactionType::Expense,
            'created_by' => \App\Enums\TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        \App\Models\TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $child1->id, 'amount' => 2000]);
        \App\Models\TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $child2->id, 'amount' => 1000]);

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(3000, $result['overview']['total_spent']);
        $this->assertCount(1, $result['categories']);

        $subIds = array_column($result['categories'][0]['subcategories'], 'id');
        $this->assertContains($child1->id, $subIds);
        $this->assertContains($child2->id, $subIds);
    }

    // ── bar_percent ────────────────────────────────────────────────────────────

    public function test_top_category_has_bar_percent_100(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        $this->makeExpense($user, $child1, 4000, '2024-03-01');
        $this->makeExpense($user, $child2, 1000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');

        // Sorted by amount desc: Housing first
        $this->assertEquals(100.0, $result['categories'][0]['bar_percent']);
        $this->assertEquals(25.0, $result['categories'][1]['bar_percent']);
    }

    public function test_top_subcategory_has_bar_percent_100(): void
    {
        $user = $this->makeAppUser();

        $parent = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent, 'Rent');
        $child2 = $this->makeChildCategory($parent, 'Utilities');

        $this->makeExpense($user, $child1, 4000, '2024-03-01');
        $this->makeExpense($user, $child2, 1000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');

        $subs = $result['categories'][0]['subcategories'];
        // Sorted by amount desc: Rent first
        $this->assertEquals(100.0, $subs[0]['bar_percent']);
        $this->assertEquals(25.0, $subs[1]['bar_percent']);
    }

    // ── difference_percent exact value ────────────────────────────────────────

    public function test_difference_percent_is_correct_when_spending_doubles(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 2000, '2024-03-10');
        $this->makeExpense($user, $child, 1000, '2024-02-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(100.0, $result['overview']['difference_percent']);
    }

    public function test_difference_percent_is_negative_when_spending_halves(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory());

        $this->makeExpense($user, $child, 1000, '2024-03-10');
        $this->makeExpense($user, $child, 2000, '2024-02-10');

        $result = $this->service->build($user, '2024-03');

        $this->assertEquals(-50.0, $result['overview']['difference_percent']);
    }

    // ── subcategory percent within parent ─────────────────────────────────────

    public function test_subcategory_percent_is_relative_to_parent_total(): void
    {
        $user = $this->makeAppUser();

        $parent = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent, 'Rent');
        $child2 = $this->makeChildCategory($parent, 'Utilities');

        $this->makeExpense($user, $child1, 3000, '2024-03-01');
        $this->makeExpense($user, $child2, 1000, '2024-03-01');

        $result = $this->service->build($user, '2024-03');

        $subs = $result['categories'][0]['subcategories'];
        $byId = array_column($subs, null, 'id');

        $this->assertEquals(75.0, $byId[$child1->id]['percent']);
        $this->assertEquals(25.0, $byId[$child2->id]['percent']);
    }

    // ── trends ────────────────────────────────────────────────────────────────

    public function test_trends_include_new_category_that_appeared_this_month(): void
    {
        $user = $this->makeAppUser();
        $child = $this->makeChildCategory($this->makeParentCategory('Housing'), 'Rent');

        // Only current month, nothing previous
        $this->makeExpense($user, $child, 2000, '2024-03-10');

        $result = $this->service->build($user, '2024-03');
        $trends = $result['trends']['categories'];

        $this->assertCount(1, $trends);
        $this->assertEquals(2000, $trends[0]['current_amount']);
        $this->assertEquals(0, $trends[0]['previous_amount']);
        $this->assertEquals('up', $trends[0]['direction']);
    }

    public function test_trends_tiebreaker_sorts_by_current_amount_desc(): void
    {
        $user = $this->makeAppUser();

        $parent1 = $this->makeParentCategory('Housing');
        $child1 = $this->makeChildCategory($parent1, 'Rent');

        $parent2 = $this->makeParentCategory('Food');
        $child2 = $this->makeChildCategory($parent2, 'Groceries');

        // Both have |difference_amount| = 1000, but Housing has higher current_amount
        $this->makeExpense($user, $child1, 3000, '2024-03-01');
        $this->makeExpense($user, $child1, 2000, '2024-02-01');

        $this->makeExpense($user, $child2, 2000, '2024-03-01');
        $this->makeExpense($user, $child2, 1000, '2024-02-01');

        $result = $this->service->build($user, '2024-03');
        $trends = $result['trends']['categories'];

        $this->assertEquals($parent1->id, $trends[0]['id']);
        $this->assertEquals($parent2->id, $trends[1]['id']);
    }
}
