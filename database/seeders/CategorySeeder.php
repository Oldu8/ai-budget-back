<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $items = require database_path('data/categories.php');
        $keyToId = [];

        foreach ($items as $item) {
            $parentId = $item['parent_key'] ? ($keyToId[$item['parent_key']] ?? null) : null;

            $category = Category::updateOrCreate(
                ['name' => $item['key']],
                [
                    'category_id' => $parentId,
                    'label' => $item['label'],
                    'visibility_tag' => $item['visibility_tag'],
                    'order' => $item['order'],
                ],
            );

            $keyToId[$item['key']] = $category->id;
        }

        // Remove categories dropped from the seed list (children first — self-FK).
        // A stale category still referenced by transaction_items/recurring_expenses
        // (both restrictOnDelete) can't be hard-deleted — skip it and warn instead of
        // aborting the rest of app:seed-initial (plans, visibility questions, admin user).
        $keys = array_column($items, 'key');
        $this->deleteStale(Category::whereNotIn('name', $keys)->whereNotNull('category_id'));
        $this->deleteStale(Category::whereNotIn('name', $keys)->whereNull('category_id'));
    }

    private function deleteStale(Builder $query): void
    {
        foreach ($query->get() as $category) {
            try {
                // Wrapped in its own transaction (a savepoint if we're already inside one,
                // e.g. under RefreshDatabase in tests) so a failed delete only rolls back
                // this one row — Postgres otherwise poisons the whole enclosing transaction.
                DB::transaction(fn () => $category->delete());
            } catch (QueryException $e) {
                if ($e->getCode() !== '23503') {
                    throw $e;
                }

                $this->command->warn("categories:seed: kept '{$category->name}' — still referenced by existing data.");
            }
        }
    }
}
