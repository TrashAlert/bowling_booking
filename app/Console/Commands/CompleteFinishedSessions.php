<?php

namespace App\Console\Commands;

use App\Services\BookingCleanup;
use Illuminate\Console\Command;

class CompleteFinishedSessions extends Command
{
    protected $signature = 'bookings:complete-finished';

    protected $description = 'Mark checked-in bookings whose lane time has ended as completed';

    public function handle(BookingCleanup $cleanup): int
    {
        $count = $cleanup->completeFinishedSessions();

        $this->info("Completed {$count} booking(s).");

        return self::SUCCESS;
    }
}
