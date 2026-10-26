<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use App\Services\BookingCleanup;
use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'bookings:release-expired-holds';

    protected $description = 'Free lanes held by online bookings that were never paid';

    public function handle(BookingCleanup $cleanup, ActivityLog $log, SchedulerHealth $health): int
    {
        $count = $log->asSystem('Scheduler', fn () => $cleanup->releaseExpiredHolds());

        $this->info("Released {$count} expired hold(s).");

        $health->recordRun($this->getName());

        return self::SUCCESS;
    }
}
