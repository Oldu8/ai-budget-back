<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

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
        // Fails if transactions still reference a removed category: reset with `migrate:fresh --seed`.
        $keys = array_column($items, 'key');
        Category::whereNotIn('name', $keys)->whereNotNull('category_id')->delete();
        Category::whereNotIn('name', $keys)->whereNull('category_id')->delete();
    }
}
