<?php

namespace App\Console\Commands;

use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;

class SeedPlansCommand extends Command
{
    protected $signature = 'plans:seed';

    protected $description = 'Seed plans from database/data/plans.php';

    public function handle(): int
    {
        $this->info('Seeding plans...');
        $this->call('db:seed', ['--class' => PlanSeeder::class]);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
