<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Services\AiTransactionDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiTransactionDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AiTransactionDraftService
    {
        return app(AiTransactionDraftService::class);
    }

    public function test_creates_draft_with_items(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'USD');
        $leaf = $this->makeCategory();

        $transaction = $this->service()->createExpenseDraft($user, [
            'total_amount' => 1000,
            'date' => '2026-01-05',
            'currency' => 'USD',
            'items' => [
                ['product' => 'Coffee', 'amount' => 1000, 'category_id' => $leaf->id],
            ],
        ], ['source' => 'test']);

        $this->assertNull($transaction->confirmed_at);
        $this->assertCount(1, $transaction->items);
    }

    public function test_falls_back_to_other_misc_category_when_ai_category_is_invalid(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'USD');
        $leaf = $this->makeCategory();
        $miscParent = Category::create(['name' => 'finance', 'label' => 'Finance']);
        $misc = Category::create(['name' => 'finance_other_misc', 'label' => 'Other', 'category_id' => $miscParent->id]);

        $transaction = $this->service()->createExpenseDraft($user, [
            'total_amount' => 500,
            'date' => '2026-01-05',
            'currency' => 'USD',
            'items' => [
                ['product' => 'Mystery', 'amount' => 500, 'category_id' => 999999],
            ],
        ], []);

        $this->assertSame($misc->id, $transaction->items->first()->category_id);
    }

    public function test_returns_no_items_when_ai_payload_has_none(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'USD');

        $transaction = $this->service()->createExpenseDraft($user, [
            'total_amount' => 0,
            'date' => '2026-01-05',
            'currency' => 'USD',
            'items' => [],
        ], []);

        $this->assertCount(0, $transaction->items);
    }

    public function test_absorbs_rounding_remainder_into_the_largest_line(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'EUR');
        $leaf = $this->makeCategory();
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'EUR', 'date' => '2026-01-05', 'rate' => 0.9, 'source' => 'ecb',
        ]);

        $transaction = $this->service()->createExpenseDraft($user, [
            'total_amount' => 1000,
            'date' => '2026-01-05',
            'currency' => 'USD',
            'items' => [
                ['product' => 'A', 'amount' => 333, 'category_id' => $leaf->id],
                ['product' => 'B', 'amount' => 333, 'category_id' => $leaf->id],
                ['product' => 'C', 'amount' => 334, 'category_id' => $leaf->id],
            ],
        ], []);

        $amounts = $transaction->items->pluck('amount')->all();
        $this->assertSame(900, $transaction->total_amount);
        $this->assertSame(900, array_sum($amounts));
    }
}
