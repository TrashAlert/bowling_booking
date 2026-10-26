<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use App\Services\BookingCleanup;
use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class CompleteFinishedSessions extends Command
{
    protected $signature = 'bookings:complete-finished';

    protected $description = 'Mark checked-in bookings whose lane time has ended as completed';

    public function handle(BookingCleanup $cleanup, ActivityLog $log, SchedulerHealth $health): int
    {
        $count = $log->asSystem('Scheduler', fn () => $cleanup->completeFinishedSessions());

        $this->info("Completed {$count} booking(s).");

        $health->recordRun($this->getName());

        return self::SUCCESS;
    }
}
