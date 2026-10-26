<?php

use App\Services\SchedulerHealth;

test('a job with no record of running counts as stopped', function () {
    $stalled = app(SchedulerHealth::class)->stalled();

    expect($stalled)->toBe([
        ['job' => 'bookings:release-expired-holds', 'lastRunAt' => null],
        ['job' => 'bookings:mark-no-shows', 'lastRunAt' => null],
        ['job' => 'bookings:complete-finished', 'lastRunAt' => null],
        ['job' => 'waitlist:process', 'lastRunAt' => null],
    ]);
});

test('a job counts as running until it has gone more than 5 minutes without a run', function () {
    $this->travelTo('2026-10-26 12:00:00');
    foreach (SchedulerHealth::JOBS as $job) {
        app(SchedulerHealth::class)->recordRun($job);
    }
    $this->travel(5)->minutes();

    expect(app(SchedulerHealth::class)->stalled())->toBe([]);
});

test('only the job that has gone quiet is reported', function () {
    $this->travelTo('2026-10-26 12:00:00');
    app(SchedulerHealth::class)->recordRun('waitlist:process');
    $this->travel(6)->minutes();
    foreach (['bookings:release-expired-holds', 'bookings:mark-no-shows', 'bookings:complete-finished'] as $job) {
        app(SchedulerHealth::class)->recordRun($job);
    }

    $stalled = app(SchedulerHealth::class)->stalled();

    expect($stalled)->toBe([['job' => 'waitlist:process', 'lastRunAt' => '2026-10-26T12:00:00+00:00']]);
});
