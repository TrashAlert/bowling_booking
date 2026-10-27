<?php

use App\Services\DemoVenue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Booking clean-up jobs, run every minute by the scheduler.
Schedule::command('bookings:release-expired-holds')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:mark-no-shows')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:complete-finished')->everyMinute()->withoutOverlapping();

// Skip parties who missed their call, then call the next ones for free lanes.
Schedule::command('waitlist:process')->everyMinute()->withoutOverlapping();

// On the public demo only: start the day afresh when the venue opens.
if (config('bowling.demo')) {
    Schedule::command('demo:reset --force')->dailyAt(DemoVenue::RESET_AT)->timezone(config('bowling.timezone'));

    // And play its customers, so the lanes and the line stay busy all day.
    Schedule::command('demo:liven')->everyTwoMinutes()->withoutOverlapping(5);
}

// Remove activity log lines older than 18 months, once a day.
Schedule::command('activity-log:prune')->dailyAt('04:00')->timezone(config('bowling.timezone'));
