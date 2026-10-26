<?php

use App\Services\SchedulerHealth;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('each every-minute job notes that it ran', function (string $job) {
    $this->artisan($job)->assertSuccessful();

    expect(collect(app(SchedulerHealth::class)->stalled())->pluck('job'))->not->toContain($job);
})->with(SchedulerHealth::JOBS);

test('every job the dashboard watches is scheduled to run each minute', function () {
    $everyMinute = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => $event->expression === '* * * * *')
        ->map(fn (Event $event) => $event->command)
        ->implode("\n");

    expect($everyMinute)->toContain(...SchedulerHealth::JOBS);
});
