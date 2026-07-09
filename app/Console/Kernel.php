<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('mantenimiento:respaldo diario')->dailyAt('02:00')->withoutOverlapping();
        $schedule->command('mantenimiento:respaldo semanal')->weeklyOn(0, '02:30')->withoutOverlapping();
        $schedule->command('mantenimiento:respaldo mensual')->monthlyOn(1, '03:00')->withoutOverlapping();
        $schedule->command('sanctum:prune-expired --hours=24')->dailyAt('03:30')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
