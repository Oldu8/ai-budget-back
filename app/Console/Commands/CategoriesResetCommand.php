<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CategoriesResetCommand extends Command
{
    protected $signature = 'categories:reset {--force : Skip confirmation}';

    protected $description = 'Truncate categories table and reset ID sequence to 1 (CASCADE deletes transaction_items, app_user_category_settings, recurring_expenses)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('This will permanently delete all categories and related data (transaction_items, app_user_category_settings, recurring_expenses). Continue?')) {
                return self::FAILURE;
            }
        }

        DB::statement('TRUNCATE TABLE categories RESTART IDENTITY CASCADE');

        $this->info('Categories table truncated. IDs will start from 1 on next insert.');
        $this->info('Run: php artisan categories:seed');

        return self::SUCCESS;
    }
}
