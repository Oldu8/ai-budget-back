<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('app:run-recurring-expenses')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // After ECB publishes its daily reference rates (~16:00 CET).
        $schedule->command('rates:sync')
            ->dailyAt('17:00')
            ->timezone('Europe/Berlin')
            ->withoutOverlapping()
            ->onOneServer();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
