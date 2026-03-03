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
                ],
            );

            $keyToId[$item['key']] = $category->id;
        }
    }
}
