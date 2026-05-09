<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Thin wrapper so BenchmarkSeeder can be called via db:seed.
 * For options (--count, --fresh) use the command directly:
 *   php artisan benchmark:seed --count=100000
 */
class BenchmarkSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->call('benchmark:seed');
    }
}
