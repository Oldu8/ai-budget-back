<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\SummaryRangeRequest;
use App\Models\AppUser;
use App\Models\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV export of expenses for a date range. Available only for Basic/Plus/Premium plans.
 * Access enforced by feature.access:csv_export middleware.
 */
class ExpenseExportController extends Controller
{
    public function export(SummaryRangeRequest $request): Response
    {
        /** @var AppUser $user */
        $user = $request->user();

        $from = $request->validated('from');
        $to = $request->validated('to');
        $user->load('settings');
        $currency = $user->settings?->currency ?? ''; // @phpstan-ignore nullsafe.neverNull

        $disabledCategoryIds = $user->disabledCategorySettings()->pluck('category_id')->all();

        $query = Transaction::query()
            ->where('app_user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->whereBetween('date', [$from, $to])
            ->where('type', TransactionType::Expense)
            ->join('transaction_items', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('categories', 'transaction_items.category_id', '=', 'categories.id')
            ->whereNotNull('categories.category_id')
            ->when(!empty($disabledCategoryIds), fn ($q) => $q->whereNotIn('transaction_items.category_id', $disabledCategoryIds))
            ->select(
                'transactions.date',
                'categories.label as category_label',
                'transaction_items.amount',
                'transaction_items.label as item_label'
            )
            ->orderBy('transactions.date')
            ->orderBy('transactions.id')
            ->orderBy('transaction_items.id');

        $filename = sprintf('expenses_%s_%s.csv', $from, $to);

        return response()->streamDownload(function () use ($query, $currency) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Category', 'Amount', 'Currency', 'Label']);

            foreach ($query->cursor() as $row) {
                $dateStr = $row->date instanceof \Carbon\Carbon
                    ? $row->date->format('Y-m-d')
                    : $row->date;
                $amountFormatted = number_format((int) $row->amount / 100, 2, '.', '');
                $label = $row->item_label ?? '';
                fputcsv($out, [$dateStr, $row->category_label, $amountFormatted, $currency, $label]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
