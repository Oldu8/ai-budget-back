<?php

namespace App\Console\Commands;

use Database\Seeders\CategorySeeder;
use Illuminate\Console\Command;

class SeedCategoriesCommand extends Command
{
    protected $signature = 'categories:seed';

    protected $description = 'Seed categories from database/data/categories.php';

    public function handle(): int
    {
        $this->info('Seeding categories...');
        // --force: without it db:seed prompts in production and silently cancels.
        $exitCode = $this->call('db:seed', ['--class' => CategorySeeder::class, '--force' => true]);
        $this->info('Done.');

        return $exitCode;
    }
}
