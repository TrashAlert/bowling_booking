<?php

namespace App\Console\Commands;

use App\Services\BookingCleanup;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'bookings:release-expired-holds';

    protected $description = 'Free lanes held by online bookings that were never paid';

    public function handle(BookingCleanup $cleanup): int
    {
        $count = $cleanup->releaseExpiredHolds();

        $this->info("Released {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
