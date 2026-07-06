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
        // --force: without it db:seed prompts in production and silently cancels.
        $exitCode = $this->call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
        $this->info('Done.');

        return $exitCode;
    }
}
