<?php

namespace App\Console\Commands;

use App\Services\BookingCleanup;
use Illuminate\Console\Command;

class MarkNoShows extends Command
{
    protected $signature = 'bookings:mark-no-shows';

    protected $description = 'Mark late reservations as no-shows and free their lanes';

    public function handle(BookingCleanup $cleanup): int
    {
        $count = $cleanup->markNoShows();

        $this->info("Marked {$count} booking(s) as no-show.");

        return self::SUCCESS;
    }
}
