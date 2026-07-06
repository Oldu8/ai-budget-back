<?php

namespace App\Console\Commands;

use Database\Seeders\StatusSeeder;
use Illuminate\Console\Command;

class SeedStatusesCommand extends Command
{
    protected $signature = 'statuses:seed';

    protected $description = 'Seed subscription/payment statuses from database/data/statuses.php';

    public function handle(): int
    {
        $this->info('Seeding statuses...');
        // --force: without it db:seed prompts in production and silently cancels.
        $exitCode = $this->call('db:seed', ['--class' => StatusSeeder::class, '--force' => true]);
        $this->info('Done.');

        return $exitCode;
    }
}
