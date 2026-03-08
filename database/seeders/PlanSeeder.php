<?php

namespace Database\Seeders;

use App\Enums\PlanType;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $items = require database_path('data/plans.php');

        foreach ($items as $item) {
            Plan::updateOrCreate(
                ['type' => $item['type']],
                [
                    'type' => PlanType::from($item['type']),
                    'label' => $item['label'],
                    'price' => $item['price'],
                    'check_limit' => $item['check_limit'],
                    'description' => $item['description'],
                ],
            );
        }
    }
}
