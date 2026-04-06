<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        $names = require database_path('data/statuses.php');

        foreach ($names as $name) {
            Status::updateOrCreate(
                ['name' => $name],
                ['name' => $name],
            );
        }
    }
}
