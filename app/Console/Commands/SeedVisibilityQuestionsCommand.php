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
        // --force: without it db:seed prompts in production and silently cancels.
        $exitCode = $this->call('db:seed', ['--class' => VisibilityQuestionSeeder::class, '--force' => true]);
        $this->info('Done.');

        return $exitCode;
    }
}
