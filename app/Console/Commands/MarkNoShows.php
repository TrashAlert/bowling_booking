<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use App\Services\BookingCleanup;
use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class MarkNoShows extends Command
{
    protected $signature = 'bookings:mark-no-shows';

    protected $description = 'Mark late reservations as no-shows and free their lanes';

    public function handle(BookingCleanup $cleanup, ActivityLog $log, SchedulerHealth $health): int
    {
        $count = $log->asSystem('Scheduler', fn () => $cleanup->markNoShows());

        $this->info("Marked {$count} booking(s) as no-show.");

        $health->recordRun($this->getName());

        return self::SUCCESS;
    }
}
