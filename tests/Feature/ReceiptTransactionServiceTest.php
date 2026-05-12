<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\ReceiptTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReceiptTransactionService;
    }

    public function test_sets_confirmed_at_on_first_confirmation(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $result = $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['category_id' => $leaf->id, 'amount' => 1000, 'label' => 'Coffee']],
        ]);

        $this->assertNotNull($result->confirmed_at);
        $this->assertNotNull($tx->fresh()->confirmed_at);
    }

    public function test_does_not_overwrite_confirmed_at_on_subsequent_edits(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $confirmedAt = now()->subHour();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => $confirmedAt,
        ]);

        $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['category_id' => $leaf->id, 'amount' => 2000, 'label' => 'Updated']],
        ]);

        // confirmed_at should be the original value, not now()
        $this->assertTrue($tx->fresh()->confirmed_at->equalTo($confirmedAt->startOfSecond()));
    }

    public function test_recalculates_total_as_sum_of_items(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 0,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $result = $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [
                ['category_id' => $leaf->id, 'amount' => 1500, 'label' => 'A'],
                ['category_id' => $leaf->id, 'amount' => 700, 'label' => 'B'],
            ],
        ]);

        $this->assertSame(2200, $result->total_amount);
        $this->assertSame(2200, $tx->fresh()->total_amount);
    }

    public function test_updates_date(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-20',
            'items' => [['category_id' => $leaf->id, 'amount' => 1000]],
        ]);

        $this->assertSame('2026-05-20', $tx->fresh()->date->toDateString());
    }

    public function test_creates_new_items_without_id(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 0,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $result = $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['category_id' => $leaf->id, 'amount' => 500, 'label' => 'New item']],
        ]);

        $this->assertCount(1, $result->items);
        $this->assertSame(500, $result->items->first()->amount);
        $this->assertSame('New item', $result->items->first()->label);
    }

    public function test_updates_existing_item_by_id(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        $item = TransactionItem::create([
            'transaction_id' => $tx->id,
            'category_id' => $leaf->id,
            'amount' => 1000,
            'label' => 'Old label',
        ]);

        $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['id' => $item->id, 'category_id' => $leaf->id, 'amount' => 1500, 'label' => 'New label']],
        ]);

        $fresh = $item->fresh();
        $this->assertSame(1500, $fresh->amount);
        $this->assertSame('New label', $fresh->label);
    }

    public function test_deletes_items_not_in_incoming_list(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 2000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        $kept = TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 1000, 'label' => 'Keep']);
        $deleted = TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 1000, 'label' => 'Remove']);

        $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['id' => $kept->id, 'category_id' => $leaf->id, 'amount' => 1000, 'label' => 'Keep']],
        ]);

        $this->assertDatabaseMissing('transaction_items', ['id' => $deleted->id]);
        $this->assertDatabaseHas('transaction_items', ['id' => $kept->id]);
    }

    public function test_deletes_all_items_when_none_have_ids(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        $old = TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 1000]);

        $result = $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['category_id' => $leaf->id, 'amount' => 500]],
        ]);

        // Old item is replaced by the new one (no ID provided → all deleted first)
        $this->assertDatabaseMissing('transaction_items', ['id' => $old->id]);
        $this->assertCount(1, $result->items);
    }

    public function test_returns_transaction_with_items_and_categories_loaded(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 0,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $result = $this->service->saveAndConfirm($tx, [
            'date' => '2026-05-01',
            'items' => [['category_id' => $leaf->id, 'amount' => 800]],
        ]);

        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
        $this->assertSame($leaf->id, $result->items->first()->category->id);
    }
}
