<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\AppUser;
use App\Models\AppUserSettings;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AnalyticsDashboardService
{
    private const DIRECTION_FLAT = 'flat';

    private const DIRECTION_UP = 'up';

    private const DIRECTION_DOWN = 'down';

    /**
     * Build analytics dashboard payload for the given YYYY-MM month (authenticated user).
     *
     * Trend lists are sorted by absolute month-to-month change (desc), then current amount (desc).
     *
     * @return array<string, mixed>
     */
    public function build(AppUser $user, string $month): array
    {
        $currentStart = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $currentEnd = $currentStart->endOfMonth();
        $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        $currentFrom = $currentStart->toDateString();
        $currentTo = $currentEnd->toDateString();
        $previousFrom = $previousStart->toDateString();
        $previousTo = $previousEnd->toDateString();
        $daysInMonth = $currentStart->daysInMonth;

        $disabled = $user->disabledCategorySettings()->pluck('category_id')->all();

        $currentSubs = $this->sumBySubcategory($user, $currentFrom, $currentTo, $disabled);
        $previousSubs = $this->sumBySubcategory($user, $previousFrom, $previousTo, $disabled);

        $currentL1 = $this->rollupLevel1($currentSubs);
        $previousL1 = $this->rollupLevel1($previousSubs);

        $totalSpent = $this->sumLevel1Amounts($currentL1);
        $previousMonthTotal = $this->sumLevel1Amounts($previousL1);

        $overviewDiff = $this->diffMeta($totalSpent, $previousMonthTotal);

        $currency = strtoupper(
            $user->settings instanceof AppUserSettings
                ? ($user->settings->currency ?? 'usd')
                : 'usd'
        );

        $categoriesPayload = $totalSpent === 0 ? [] : $this->buildCategories(
            $currentL1,
            $currentSubs,
            $previousL1,
            $previousSubs,
            $totalSpent,
        );

        $biggestCategory = $this->pickBiggestCategory($currentL1, $totalSpent);

        return [
            'month' => $month,
            'currency' => $currency,
            'period' => [
                'from' => $currentFrom,
                'to' => $currentTo,
                'previous_from' => $previousFrom,
                'previous_to' => $previousTo,
            ],
            'overview' => [
                'total_spent' => $totalSpent,
                'previous_month_total' => $previousMonthTotal,
                ...$overviewDiff,
                'average_per_day' => $totalSpent === 0 ? 0 : intdiv($totalSpent, $daysInMonth),
                'biggest_category' => $biggestCategory,
            ],
            'categories' => $categoriesPayload,
            'trends' => [
                'categories' => $this->buildTrendsLevel1($currentL1, $previousL1),
                'subcategories' => $this->buildTrendsLevel2($currentSubs, $previousSubs),
            ],
        ];
    }

    /**
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $level1
     */
    private function sumLevel1Amounts(array $level1): int
    {
        return (int) array_sum(array_column($level1, 'amount'));
    }

    /**
     * Confirmed expense line totals per leaf category for the date range.
     *
     * For index checks: in psql run EXPLAIN (ANALYZE, BUFFERS) on the query from toSql() with bindings
     * (or from the query log) on representative data. Migrations already add
     * transactions (app_user_id, date, type) and transaction_items (category_id) — see
     * 2026_03_02_100002_add_transaction_indexes_for_monthly_category_queries. EXPLAIN confirms the planner
     * uses them for this query shape.
     *
     * @param  array<int>  $disabledCategoryIds
     * @return array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>
     */
    private function sumBySubcategory(AppUser $user, string $from, string $to, array $disabledCategoryIds): array
    {
        $rows = Transaction::query()
            ->where('app_user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->whereBetween('date', [$from, $to])
            ->where('type', TransactionType::Expense)
            ->join('transaction_items', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('categories as subcat', 'transaction_items.category_id', '=', 'subcat.id')
            ->join('categories as parent', 'subcat.category_id', '=', 'parent.id')
            ->when(! empty($disabledCategoryIds), fn ($q) => $q->whereNotIn('transaction_items.category_id', $disabledCategoryIds))
            ->groupBy('subcat.id', 'parent.id')
            ->select([
                'subcat.id as subcategory_id',
                'subcat.name as sub_slug',
                'subcat.label as sub_label',
                'parent.id as parent_id',
                'parent.name as parent_slug',
                'parent.label as parent_label',
                DB::raw('SUM(transaction_items.amount) as total'),
            ])
            ->toBase()
            ->get();

        return $rows
            ->mapWithKeys(fn (object $row) => [
                (int) $row->subcategory_id => [
                    'amount'       => (int) $row->total,
                    'slug'         => (string) $row->sub_slug,
                    'label'        => (string) $row->sub_label,
                    'parent_id'    => (int) $row->parent_id,
                    'parent_slug'  => (string) $row->parent_slug,
                    'parent_label' => (string) $row->parent_label,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $bySub
     * @return array<int, array{id: int, slug: string, label: string, amount: int}>
     */
    private function rollupLevel1(array $bySub): array
    {
        $acc = [];
        foreach ($bySub as $sub) {
            $pid = $sub['parent_id'];
            if (! isset($acc[$pid])) {
                $acc[$pid] = [
                    'id' => $pid,
                    'slug' => $sub['parent_slug'],
                    'label' => $sub['parent_label'],
                    'amount' => 0,
                ];
            }
            $acc[$pid]['amount'] += $sub['amount'];
        }

        return $acc;
    }

    /**
     * Group Level 2 rows by parent id for nested category output.
     *
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $bySub
     * @return array<int, array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>>
     */
    private function subsGroupedByParent(array $bySub): array
    {
        $byParent = [];
        foreach ($bySub as $id => $sub) {
            $byParent[$sub['parent_id']][$id] = $sub;
        }

        return $byParent;
    }

    /**
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $currentL1
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $currentSubs
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $previousL1
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $previousSubs
     * @return list<array<string, mixed>>
     */
    private function buildCategories(
        array $currentL1,
        array $currentSubs,
        array $previousL1,
        array $previousSubs,
        int $totalSpent,
    ): array {
        $sorted = array_values($currentL1);
        usort($sorted, $this->sortByAmountLabelId(...));

        $maxL1 = max(array_column($sorted, 'amount')) ?: 0;
        $byParentSubs = $this->subsGroupedByParent($currentSubs);

        $out = [];
        foreach ($sorted as $cat) {
            $pid = $cat['id'];
            $amount = $cat['amount'];
            $prevAmount = $previousL1[$pid]['amount'] ?? 0;

            $subList = $this->buildSubcategoryRows(
                $byParentSubs[$pid] ?? [],
                $previousSubs,
                $amount,
            );

            $catDiff = $this->diffMeta($amount, $prevAmount);

            $out[] = [
                'id' => $pid,
                'slug' => $cat['slug'],
                'label' => $cat['label'],
                'amount' => $amount,
                'percent' => $totalSpent === 0 ? 0.0 : $this->roundPercent(100 * $amount / $totalSpent),
                'bar_percent' => $this->relativeBarPercent($amount, $maxL1),
                'previous_amount' => $prevAmount,
                'difference_amount' => $catDiff['difference_amount'],
                'difference_percent' => $catDiff['difference_percent'],
                'direction' => $catDiff['direction'],
                'subcategories' => $subList,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $subs
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $previousSubs
     * @return list<array<string, mixed>>
     */
    private function buildSubcategoryRows(array $subs, array $previousSubs, int $parentAmount): array
    {
        $subList = [];
        foreach ($subs as $sid => $sub) {
            $prevSub = $previousSubs[$sid]['amount'] ?? 0;
            $diff = $this->diffMeta($sub['amount'], $prevSub);
            $subList[] = [
                'id' => $sid,
                'slug' => $sub['slug'],
                'label' => $sub['label'],
                'amount' => $sub['amount'],
                'percent' => $parentAmount === 0 ? 0.0 : $this->roundPercent(100 * $sub['amount'] / $parentAmount),
                'bar_percent' => 0.0,
                'previous_amount' => $prevSub,
                'difference_amount' => $diff['difference_amount'],
                'difference_percent' => $diff['difference_percent'],
                'direction' => $diff['direction'],
            ];
        }
        usort($subList, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        $maxSub = max(array_column($subList, 'amount')) ?: 0;
        foreach ($subList as $i => $row) {
            $subList[$i]['bar_percent'] = $this->relativeBarPercent($row['amount'], $maxSub);
        }

        return $subList;
    }

    /**
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $currentL1
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $previousL1
     * @return list<array<string, mixed>>
     */
    private function buildTrendsLevel1(array $currentL1, array $previousL1): array
    {
        return $this->sortedTrendRows(array_keys(array_replace($previousL1, $currentL1)), function (int $id) use ($currentL1, $previousL1): ?array {
            return $this->trendRowForLevel1($id, $currentL1, $previousL1);
        });
    }

    /**
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $currentSubs
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $previousSubs
     * @return list<array<string, mixed>>
     */
    private function buildTrendsLevel2(array $currentSubs, array $previousSubs): array
    {
        return $this->sortedTrendRows(array_keys(array_replace($previousSubs, $currentSubs)), function (int $id) use ($currentSubs, $previousSubs): ?array {
            return $this->trendRowForLevel2($id, $currentSubs, $previousSubs);
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int): ?array<string, mixed>  $makeRow
     * @return list<array<string, mixed>>
     */
    private function sortedTrendRows(array $ids, callable $makeRow): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $row = $makeRow($id);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        usort($rows, $this->sortTrends(...));

        return $rows;
    }

    /**
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $currentL1
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $previousL1
     * @return array<string, mixed>|null
     */
    private function trendRowForLevel1(int $id, array $currentL1, array $previousL1): ?array
    {
        $cur = $currentL1[$id] ?? null;
        $prev = $previousL1[$id] ?? null;
        $meta = $cur ?? $prev;
        if ($meta === null) {
            return null;
        }

        $currentAmount = $cur['amount'] ?? 0;
        $previousAmount = $prev['amount'] ?? 0;

        return [
            'id' => $id,
            'slug' => $meta['slug'],
            'label' => $meta['label'],
            'current_amount' => $currentAmount,
            'previous_amount' => $previousAmount,
            ...$this->diffMeta($currentAmount, $previousAmount),
        ];
    }

    /**
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $currentSubs
     * @param  array<int, array{amount: int, slug: string, label: string, parent_id: int, parent_slug: string, parent_label: string}>  $previousSubs
     * @return array<string, mixed>|null
     */
    private function trendRowForLevel2(int $id, array $currentSubs, array $previousSubs): ?array
    {
        $cur = $currentSubs[$id] ?? null;
        $prev = $previousSubs[$id] ?? null;
        $meta = $cur ?? $prev;
        if ($meta === null) {
            return null;
        }

        $currentAmount = $cur['amount'] ?? 0;
        $previousAmount = $prev['amount'] ?? 0;

        return [
            'id' => $id,
            'parent_id' => $meta['parent_id'],
            'parent_slug' => $meta['parent_slug'],
            'parent_label' => $meta['parent_label'],
            'slug' => $meta['slug'],
            'label' => $meta['label'],
            'current_amount' => $currentAmount,
            'previous_amount' => $previousAmount,
            ...$this->diffMeta($currentAmount, $previousAmount),
        ];
    }

    /**
     * @param  array<int, array{id: int, slug: string, label: string, amount: int}>  $currentL1
     * @return array<string, mixed>|null
     */
    private function pickBiggestCategory(array $currentL1, int $totalSpent): ?array
    {
        if ($totalSpent === 0 || $currentL1 === []) {
            return null;
        }

        $sorted = array_values($currentL1);
        usort($sorted, $this->sortByAmountLabelId(...));
        $top = $sorted[0];

        return [
            'id' => $top['id'],
            'slug' => $top['slug'],
            'label' => $top['label'],
            'amount' => $top['amount'],
            'percent' => $this->roundPercent(100 * $top['amount'] / $totalSpent),
        ];
    }

    private function sortByAmountLabelId(array $a, array $b): int
    {
        if ($a['amount'] !== $b['amount']) {
            return $b['amount'] <=> $a['amount'];
        }
        $cmp = strcmp($a['label'], $b['label']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return $a['id'] <=> $b['id'];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sortTrends(array $a, array $b): int
    {
        $da = abs((int) $a['difference_amount']);
        $db = abs((int) $b['difference_amount']);
        if ($da !== $db) {
            return $db <=> $da;
        }

        return ((int) $b['current_amount']) <=> ((int) $a['current_amount']);
    }

    /**
     * Bar width: 100% for the largest amount in the group; others proportional.
     */
    private function relativeBarPercent(int $amount, int $maxInGroup): float
    {
        if ($maxInGroup === 0) {
            return 0.0;
        }

        return $amount === $maxInGroup
            ? 100.0
            : $this->roundPercent(100 * $amount / $maxInGroup);
    }

    /**
     * @return array{difference_amount: int, difference_percent: float|null, direction: string}
     */
    private function diffMeta(int $current, int $previous): array
    {
        $differenceAmount = $current - $previous;

        if ($current === $previous) {
            return [
                'difference_amount' => 0,
                'difference_percent' => 0.0,
                'direction' => self::DIRECTION_FLAT,
            ];
        }

        if ($previous === 0 && $current > 0) {
            return [
                'difference_amount' => $differenceAmount,
                'difference_percent' => null,
                'direction' => self::DIRECTION_UP,
            ];
        }

        $pct = $this->roundPercent(($current - $previous) / $previous * 100);

        if ($current > $previous) {
            return [
                'difference_amount' => $differenceAmount,
                'difference_percent' => $pct,
                'direction' => self::DIRECTION_UP,
            ];
        }

        return [
            'difference_amount' => $differenceAmount,
            'difference_percent' => $pct,
            'direction' => self::DIRECTION_DOWN,
        ];
    }

    private function roundPercent(float $value): float
    {
        return round($value, 2);
    }
}
