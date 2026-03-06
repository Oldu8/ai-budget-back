<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\SummaryRangeRequest;
use App\Http\Responses\CategorySummaryResponse;
use App\Http\Responses\SummaryResponse;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;

class TransactionSummaryController extends Controller
{
    public function index(SummaryRangeRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $from = $request->validated('from');
        $to = $request->validated('to');

        $rows = Transaction::query()
            ->where('app_user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->whereBetween('date', [$from, $to])
            ->where('type', TransactionType::Expense)
            ->join('transaction_items', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('categories as subcat', 'transaction_items.category_id', '=', 'subcat.id')
            ->whereNotNull('subcat.category_id')
            ->selectRaw('transactions.date as date, subcat.category_id as category_id, SUM(transaction_items.amount) as total')
            ->groupBy('transactions.date', 'subcat.category_id')
            ->get();

        $days = [];
        $totalsByCategory = [];
        $grandTotal = 0;

        foreach ($rows as $row) {
            $dateStr = $row->date instanceof \Carbon\Carbon
                ? $row->date->format('Y-m-d')
                : $row->date;
            $catId = (string) $row->category_id;
            $total = (int) $row->total;

            $days[$dateStr] = $days[$dateStr] ?? [];
            $days[$dateStr][$catId] = $total;

            $totalsByCategory[$catId] = ($totalsByCategory[$catId] ?? 0) + $total;
            $grandTotal += $total;
        }

        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            if (! isset($days[$dateStr])) {
                $days[$dateStr] = [];
            }
        }
        ksort($days);

        $response = new SummaryResponse(
            from: $from,
            to: $to,
            days: $days,
            meta: [
                'totals_by_category' => $totalsByCategory,
                'grand_total' => $grandTotal,
            ],
        );

        return response()->json($response->toArray());
    }

    public function show(SummaryRangeRequest $request, Category $category): JsonResponse
    {
        $category->loadMissing('children');

        if ($category->category_id !== null) {
            abort(404, 'Category must be a top-level category.');
        }

        $userId = $request->user()->id;
        $from = $request->validated('from');
        $to = $request->validated('to');
        $childIds = $category->children->pluck('id')->all();

        if ($childIds === []) {
            $days = $this->emptyDaysForRange($from, $to);
            $response = new CategorySummaryResponse(
                category: [
                    'id' => $category->id,
                    'slug' => $category->name,
                    'label' => $category->label,
                ],
                from: $from,
                to: $to,
                days: $days,
                meta: ['totals_by_subcategory' => [], 'grand_total' => 0],
            );
            return response()->json($response->toArray());
        }

        $rows = Transaction::query()
            ->where('app_user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->whereBetween('date', [$from, $to])
            ->where('type', TransactionType::Expense)
            ->join('transaction_items', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transaction_items.category_id', $childIds)
            ->selectRaw('transactions.date as date, transaction_items.category_id as subcategory_id, SUM(transaction_items.amount) as total')
            ->groupBy('transactions.date', 'transaction_items.category_id')
            ->get();

        $days = [];
        $totalsBySubcategory = [];
        $grandTotal = 0;

        foreach ($rows as $row) {
            $dateStr = $row->date instanceof \Carbon\Carbon
                ? $row->date->format('Y-m-d')
                : $row->date;
            $subId = (string) $row->subcategory_id;
            $total = (int) $row->total;

            $days[$dateStr] = $days[$dateStr] ?? [];
            $days[$dateStr][$subId] = $total;

            $totalsBySubcategory[$subId] = ($totalsBySubcategory[$subId] ?? 0) + $total;
            $grandTotal += $total;
        }

        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            if (! isset($days[$dateStr])) {
                $days[$dateStr] = [];
            }
        }
        ksort($days);

        $response = new CategorySummaryResponse(
            category: [
                'id' => $category->id,
                'slug' => $category->name,
                'label' => $category->label,
            ],
            from: $from,
            to: $to,
            days: $days,
            meta: [
                'totals_by_subcategory' => $totalsBySubcategory,
                'grand_total' => $grandTotal,
            ],
        );

        return response()->json($response->toArray());
    }

    private function emptyDaysForRange(string $from, string $to): array
    {
        $days = [];
        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            $days[$date->format('Y-m-d')] = [];
        }
        return $days;
    }
}
