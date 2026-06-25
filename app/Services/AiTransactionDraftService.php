<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Currency\ExchangeRateService;
use Illuminate\Support\Facades\DB;

/**
 * Persists an unconfirmed AI expense draft (one transaction + line items). Does not increment scan quota.
 *
 * The AI extracts amounts in the currency actually spoken/printed; this service
 * converts them to the user's accounting currency using the historical rate on
 * the transaction date, and freezes the original + converted + rate metadata.
 */
final class AiTransactionDraftService
{
    public function __construct(
        private ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array{total_amount: int|string, date: string, currency?: string|null, items: iterable<int, array<string, mixed>>}  $aiPayload
     * @param  array<string, mixed>  $rawAiData
     */
    public function createExpenseDraft(
        AppUser $user,
        array $aiPayload,
        array $rawAiData,
        ?string $imgPath = null,
    ): Transaction {
        $user->loadMissing('settings');
        $targetCurrency = data_get($user, 'settings.currency') ?? 'USD';
        $originalCurrency = strtoupper((string) ($aiPayload['currency'] ?? '')) ?: $targetCurrency;
        $date = (string) $aiPayload['date'];
        $originalTotal = (int) $aiPayload['total_amount'];

        // May throw RateDateTooEarlyException / UnsupportedCurrencyException —
        // controllers map these to a 422. Kept outside the DB transaction below.
        $conversion = $this->exchangeRates->convert($originalTotal, $originalCurrency, $targetCurrency, $date);

        $categories = Category::leafCategoriesForUser($user);
        $validCategoryIds = $categories->pluck('id')->toArray();
        $fallbackCategoryId = $categories->firstWhere('name', 'finance_other_misc')?->id;

        $items = is_array($aiPayload['items']) ? array_values($aiPayload['items']) : iterator_to_array($aiPayload['items']);
        $convertedAmounts = $this->reconcileItemAmounts($items, $conversion->rate, $conversion->amount);

        return DB::transaction(function () use (
            $user, $rawAiData, $imgPath, $date,
            $targetCurrency, $originalCurrency, $originalTotal, $conversion,
            $items, $convertedAmounts, $validCategoryIds, $fallbackCategoryId,
        ): Transaction {
            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => $conversion->amount,
                'currency' => $targetCurrency,
                'original_currency' => $originalCurrency,
                'original_total_amount' => $originalTotal,
                'exchange_rate' => $conversion->rate,
                'rate_date' => $conversion->rateDate,
                'rate_source' => $conversion->rateSource,
                'rate_is_approximate' => $conversion->approximate,
                'date' => $date,
                'type' => TransactionType::Expense,
                'created_by' => TransactionCreatedBy::Ai,
                'confirmed_at' => null,
                'img_path' => $imgPath,
                'raw_ai_data' => $rawAiData,
            ]);

            foreach ($items as $i => $item) {
                $categoryId = in_array((int) ($item['category_id'] ?? 0), $validCategoryIds, true)
                    ? (int) $item['category_id']
                    : $fallbackCategoryId;

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $categoryId,
                    'amount' => $convertedAmounts[$i],
                    'label' => $item['product'] ?? null,
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Convert each line amount by the same rate, then absorb the rounding
     * remainder into the largest line so the items still sum to the converted
     * total (the invariant the rest of the app relies on).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    private function reconcileItemAmounts(array $items, float $rate, int $convertedTotal): array
    {
        if ($items === []) {
            return [];
        }

        $converted = [];
        foreach ($items as $item) {
            $converted[] = (int) round(((int) ($item['amount'] ?? 0)) * $rate);
        }

        $diff = $convertedTotal - array_sum($converted);
        if ($diff !== 0) {
            $maxIndex = array_keys($converted, max($converted))[0];
            $converted[$maxIndex] += $diff;
        }

        return $converted;
    }
}
