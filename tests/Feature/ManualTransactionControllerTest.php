<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/transactions ─────────────────────────────────────────────────

    public function test_index_returns_only_confirmed_transactions(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        // Confirmed transaction
        $confirmed = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create([
            'transaction_id' => $confirmed->id,
            'category_id' => $category->id,
            'amount' => 1000,
        ]);

        // Draft (unconfirmed)
        $draft = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 500,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $response = $this->apiAs($user)->getJson('/api/transactions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($confirmed->id, $ids->all());
        $this->assertNotContains($draft->id, $ids->all());
    }

    public function test_index_does_not_return_other_users_transactions(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 999,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create([
            'transaction_id' => $tx->id,
            'category_id' => $category->id,
            'amount' => 999,
        ]);

        $response = $this->apiAs($user)->getJson('/api/transactions');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_index_filters_by_date_range(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        $inRange = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 100,
            'date' => '2024-03-15',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $inRange->id, 'category_id' => $category->id, 'amount' => 100]);

        $outOfRange = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 200,
            'date' => '2024-01-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $outOfRange->id, 'category_id' => $category->id, 'amount' => 200]);

        $response = $this->apiAs($user)->getJson('/api/transactions?from=2024-03-01&to=2024-03-31');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($inRange->id, $ids->all());
        $this->assertNotContains($outOfRange->id, $ids->all());
    }

    // ── POST /api/transactions ────────────────────────────────────────────────

    public function test_store_creates_confirmed_transaction(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        $response = $this->apiAs($user)->postJson('/api/transactions', [
            'total_amount' => 2500,
            'date' => '2024-06-01',
            'type' => 'expense',
            'category_id' => $category->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'app_user_id' => $user->id,
            'total_amount' => 2500,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'category_id' => $category->id,
            'amount' => 2500,
        ]);

        // confirmed_at must be set
        $tx = Transaction::where('app_user_id', $user->id)->first();
        $this->assertNotNull($tx->confirmed_at);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->postJson('/api/transactions', []);

        $response->assertUnprocessable();
    }

    public function test_store_validates_positive_amount(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        $response = $this->apiAs($user)->postJson('/api/transactions', [
            'total_amount' => 0,
            'date' => '2024-06-01',
            'type' => 'expense',
            'category_id' => $category->id,
        ]);

        $response->assertUnprocessable();
    }

    // ── GET /api/transactions/{id} ────────────────────────────────────────────

    public function test_show_returns_transaction(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 3000,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 3000]);

        $response = $this->apiAs($user)->getJson("/api/transactions/{$tx->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $tx->id);
    }

    public function test_show_returns_404_for_other_users_transaction(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 1000,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 1000]);

        $response = $this->apiAs($user)->getJson("/api/transactions/{$tx->id}");

        $response->assertNotFound();
    }

    // ── PUT /api/transactions/{id} ────────────────────────────────────────────

    public function test_update_changes_amount_and_category(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();
        $newCategory = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 1000]);

        $response = $this->apiAs($user)->putJson("/api/transactions/{$tx->id}", [
            'total_amount' => 2000,
            'category_id' => $newCategory->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'total_amount' => 2000]);
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $tx->id,
            'category_id' => $newCategory->id,
            'amount' => 2000,
        ]);
    }

    public function test_update_returns_404_for_other_users_transaction(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 1000,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 1000]);

        $response = $this->apiAs($user)->putJson("/api/transactions/{$tx->id}", [
            'total_amount' => 9999,
        ]);

        $response->assertNotFound();
    }

    // ── DELETE /api/transactions/{id} ─────────────────────────────────────────

    public function test_destroy_soft_deletes_transaction(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 500,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 500]);

        $response = $this->apiAs($user)->deleteJson("/api/transactions/{$tx->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Deleted']);

        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
    }

    public function test_destroy_returns_404_for_other_users_transaction(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $category = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 500,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $category->id, 'amount' => 500]);

        $response = $this->apiAs($user)->deleteJson("/api/transactions/{$tx->id}");

        $response->assertNotFound();
    }
}
