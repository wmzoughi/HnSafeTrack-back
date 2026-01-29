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
        // Exécuter toutes les 5 minutes
        $schedule->command('affectation:update-auto')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/affectation-auto-update.log'));
        $schedule->command('affectations:refresh-statut')->everyMinute();   
        
         // Exécuter toutes les minutes (comme Odoo)
        $schedule->command('pointage:process-departures')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping();
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
