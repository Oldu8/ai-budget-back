<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;

/**
 * Persists an unconfirmed AI expense draft (one transaction + line items). Does not increment scan quota.
 */
final class AiTransactionDraftService
{
    /**
     * @param  array{total_amount: int|string, date: string, items: iterable<int, array<string, mixed>>}  $aiPayload
     * @param  array<string, mixed>  $rawAiData
     */
    public function createExpenseDraft(
        AppUser $user,
        array $aiPayload,
        array $rawAiData,
        ?string $imgPath = null,
    ): Transaction {
        $categories = Category::leafCategoriesForUser($user);
        $validCategoryIds = $categories->pluck('id')->toArray();
        $fallbackCategoryId = $categories->firstWhere('name', 'finance_other_misc')?->id;

        return DB::transaction(function () use ($user, $aiPayload, $rawAiData, $imgPath, $validCategoryIds, $fallbackCategoryId): Transaction {
            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => (int) $aiPayload['total_amount'],
                'date' => $aiPayload['date'],
                'type' => TransactionType::Expense,
                'created_by' => TransactionCreatedBy::Ai,
                'confirmed_at' => null,
                'img_path' => $imgPath,
                'raw_ai_data' => $rawAiData,
            ]);

            foreach ($aiPayload['items'] as $item) {
                $categoryId = in_array((int) ($item['category_id'] ?? 0), $validCategoryIds, true)
                    ? (int) $item['category_id']
                    : $fallbackCategoryId;

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $categoryId,
                    'amount' => (int) ($item['amount'] ?? 0),
                    'label' => $item['product'] ?? null,
                ]);
            }

            return $transaction;
        });
    }
}
