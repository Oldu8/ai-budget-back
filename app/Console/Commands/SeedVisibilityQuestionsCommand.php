<?php

namespace App\Console\Commands;

use Database\Seeders\VisibilityQuestionSeeder;
use Illuminate\Console\Command;

class SeedVisibilityQuestionsCommand extends Command
{
    protected $signature = 'visibility-questions:seed';

    protected $description = 'Seed visibility questions into visibility_questions table';

    public function handle(): int
    {
        $this->info('Seeding visibility questions...');
        $this->call('db:seed', ['--class' => VisibilityQuestionSeeder::class]);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
