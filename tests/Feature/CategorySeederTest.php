<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_stale_category_still_referenced_by_transaction_items(): void
    {
        $referenced = Category::create(['name' => 'legacy_referenced', 'label' => 'Legacy referenced']);
        Category::create(['name' => 'legacy_unused', 'label' => 'Legacy unused']);

        $user = $this->makeAppUser();
        $transaction = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 100,
            'date' => now()->toDateString(),
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'category_id' => $referenced->id,
            'amount' => 100,
        ]);

        $this->artisan('categories:seed')->assertSuccessful();

        $this->assertDatabaseHas('categories', ['name' => 'legacy_referenced']);
        $this->assertDatabaseMissing('categories', ['name' => 'legacy_unused']);
        $this->assertDatabaseHas('categories', ['name' => 'groceries']);
    }
}
