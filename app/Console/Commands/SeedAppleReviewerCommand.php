<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\AppleReviewerSeeder;
use Illuminate\Console\Command;

class SeedAppleReviewerCommand extends Command
{
    protected $signature = 'reviewer:seed';

    protected $description = 'Create/refresh the demo account for Apple App Review (needs APPLE_REVIEWER_PASSWORD env)';

    public function handle(): int
    {
        // --force: without it db:seed prompts in production and silently cancels.
        return $this->call('db:seed', ['--class' => AppleReviewerSeeder::class, '--force' => true]);
    }
}
