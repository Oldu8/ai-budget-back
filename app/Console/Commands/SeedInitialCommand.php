<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SeedInitialCommand extends Command
{
    protected $signature = 'app:seed-initial';

    protected $description = 'Run all initial seeders: statuses, categories, plans, visibility questions, admin user';

    public function handle(): int
    {
        $this->info('Running initial seeders...');

        // --force on db:seed: without it the call prompts in production and silently cancels.
        $exitCodes = [
            $this->call('statuses:seed'),
            $this->call('categories:seed'),
            $this->call('plans:seed'),
            $this->call('visibility-questions:seed'),
            $this->call('db:seed', ['--class' => 'AdminUserSeeder', '--force' => true]),
        ];

        if (array_sum($exitCodes) > 0) {
            $this->error('One or more seeders failed.');

            return self::FAILURE;
        }

        $this->info('All initial seeders completed.');

        return self::SUCCESS;
    }
}
