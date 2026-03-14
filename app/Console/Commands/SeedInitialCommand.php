<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SeedInitialCommand extends Command
{
    protected $signature = 'app:seed-initial';

    protected $description = 'Run all initial seeders: categories, plans, visibility questions, admin user';

    public function handle(): int
    {
        $this->info('Running initial seeders...');

        $this->call('categories:seed');
        $this->call('plans:seed');
        $this->call('visibility-questions:seed');
        $this->call('db:seed', ['--class' => 'AdminUserSeeder']);

        $this->info('All initial seeders completed.');

        return self::SUCCESS;
    }
}
