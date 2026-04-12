<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * For admin user creation use: php artisan app:seed-initial
     */
    public function run(): void
    {
        $this->call([
            StatusSeeder::class,
            PlanSeeder::class,
            CategorySeeder::class,
            VisibilityQuestionSeeder::class,
        ]);
    }
}
