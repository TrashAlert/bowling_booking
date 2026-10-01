<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Booking clean-up jobs, run every minute by the scheduler.
Schedule::command('bookings:release-expired-holds')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:mark-no-shows')->everyMinute()->withoutOverlapping();

// Skip parties who missed their call, then call the next ones for free lanes.
Schedule::command('waitlist:process')->everyMinute()->withoutOverlapping();
