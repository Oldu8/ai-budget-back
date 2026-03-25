<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;

class ReceiptTransactionService
{
    /**
     * Apply line items + date, recalculate total, set confirmed_at — single DB transaction.
     */
    public function saveAndConfirm(Transaction $transaction, array $validated): Transaction
    {
        return DB::transaction(function () use ($transaction, $validated) {
            $transaction->update(['date' => $validated['date']]);

            $incomingIds = collect($validated['items'])->pluck('id')->filter()->values()->all();

            if ($incomingIds === []) {
                $transaction->items()->delete();
            } else {
                $transaction->items()->whereNotIn('id', $incomingIds)->delete();
            }

            $total = 0;
            foreach ($validated['items'] as $item) {
                $total += $item['amount'];
                if (! empty($item['id'])) {
                    TransactionItem::query()
                        ->where('transaction_id', $transaction->id)
                        ->whereKey($item['id'])
                        ->update([
                            'label' => $item['label'] ?? null,
                            'amount' => $item['amount'],
                            'category_id' => $item['category_id'],
                        ]);
                } else {
                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'category_id' => $item['category_id'],
                        'amount' => $item['amount'],
                        'label' => $item['label'] ?? null,
                    ]);
                }
            }

            $transaction->update([
                'total_amount' => $total,
                'confirmed_at' => now(),
            ]);

            return $transaction->fresh()->load('items.category');
        });
    }
}
