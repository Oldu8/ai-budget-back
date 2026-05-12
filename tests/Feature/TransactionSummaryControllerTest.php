<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUserCategorySetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransactionSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_returns_days_dict_with_category_totals(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 1000, '2026-05-01');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $response->assertOk();
        $days = $response->json('days');
        $this->assertArrayHasKey('2026-05-01', $days);
        $this->assertSame(1000, $days['2026-05-01'][(string) $leaf->parent->id]);
    }

    public function test_index_grand_total_sums_all_expenses(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 1000, '2026-05-01');
        $this->makeConfirmedExpense($user->id, $leaf->id, 2000, '2026-05-02');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $this->assertSame(3000, $response->json('meta.grand_total'));
    }

    public function test_index_fills_missing_dates_with_empty_objects(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 500, '2026-05-01');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-03');

        $days = $response->json('days');
        $this->assertArrayHasKey('2026-05-02', $days);
        $this->assertEmpty($days['2026-05-02']);
        $this->assertArrayHasKey('2026-05-03', $days);
    }

    public function test_index_excludes_unconfirmed_transactions(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeDraftExpense($user->id, $leaf->id, 9999, '2026-05-01');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $this->assertSame(0, $response->json('meta.grand_total'));
    }

    public function test_index_excludes_income_transactions(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 5000,
            'date' => '2026-05-01',
            'type' => TransactionType::Income,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 5000]);

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $this->assertSame(0, $response->json('meta.grand_total'));
    }

    public function test_index_excludes_other_users_transactions(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($other->id, $leaf->id, 9999, '2026-05-01');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $this->assertSame(0, $response->json('meta.grand_total'));
    }

    public function test_index_excludes_disabled_categories(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 1000, '2026-05-01');

        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $leaf->id]);

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-31');

        $this->assertSame(0, $response->json('meta.grand_total'));
    }

    public function test_index_days_are_sorted(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 100, '2026-05-03');
        $this->makeConfirmedExpense($user->id, $leaf->id, 200, '2026-05-01');

        $response = $this->apiAs($user)->getJson('/api/transactions/summary?from=2026-05-01&to=2026-05-03');

        $keys = array_keys($response->json('days'));
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_404_for_leaf_category(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $this->apiAs($user)->getJson("/api/transactions/summary/{$leaf->id}?from=2026-05-01&to=2026-05-31")
            ->assertNotFound();
    }

    public function test_show_returns_subcategory_totals(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $parent = $leaf->parent;

        $this->makeConfirmedExpense($user->id, $leaf->id, 1500, '2026-05-01');

        $response = $this->apiAs($user)->getJson("/api/transactions/summary/{$parent->id}?from=2026-05-01&to=2026-05-31");

        $response->assertOk();
        $this->assertSame(1500, $response->json('meta.grand_total'));
        $this->assertArrayHasKey((string) $leaf->id, $response->json('meta.totals_by_subcategory'));
    }

    public function test_show_fills_missing_dates(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $parent = $leaf->parent;

        $this->makeConfirmedExpense($user->id, $leaf->id, 500, '2026-05-01');

        $response = $this->apiAs($user)->getJson("/api/transactions/summary/{$parent->id}?from=2026-05-01&to=2026-05-03");

        $days = $response->json('days');
        $this->assertArrayHasKey('2026-05-02', $days);
        $this->assertEmpty($days['2026-05-02']);
    }

    public function test_show_returns_empty_days_when_all_children_disabled(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $parent = $leaf->parent;

        $this->makeConfirmedExpense($user->id, $leaf->id, 500, '2026-05-01');
        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $leaf->id]);

        $response = $this->apiAs($user)->getJson("/api/transactions/summary/{$parent->id}?from=2026-05-01&to=2026-05-01");

        $response->assertOk();
        $this->assertSame(0, $response->json('meta.grand_total'));
        $this->assertNotEmpty($response->json('days'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeConfirmedExpense(int $userId, int $categoryId, int $amount, string $date): Transaction
    {
        $tx = Transaction::create([
            'app_user_id' => $userId,
            'total_amount' => $amount,
            'date' => $date,
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $categoryId, 'amount' => $amount]);

        return $tx;
    }

    private function makeDraftExpense(int $userId, int $categoryId, int $amount, string $date): Transaction
    {
        $tx = Transaction::create([
            'app_user_id' => $userId,
            'total_amount' => $amount,
            'date' => $date,
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $categoryId, 'amount' => $amount]);

        return $tx;
    }
}
