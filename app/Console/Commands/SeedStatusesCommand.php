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
        $this->call('db:seed', ['--class' => StatusSeeder::class]);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
