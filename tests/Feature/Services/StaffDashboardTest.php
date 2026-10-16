<?php

use App\Enums\LaneClosureReason;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\LaneRepair;
use App\Services\BookingCleanup;
use App\Services\BookingService;
use App\Services\LaneClosures;
use App\Services\StaffDashboard;
use App\Services\WaitlistService;
use App\Services\WaitlistSettings;

test('a reservation on a lane that has gone out of order is listed as needing a move', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    $closed = Lane::factory()->create(['number' => 4]);
    $booking = reserveLanes($closed, venueTime('2026-10-07 18:00'), name: 'Aisyah');
    reserveLanes(Lane::factory()->create(), venueTime('2026-10-07 18:00'), name: 'On an open lane');
    app(LaneClosures::class)->closeForRepair($closed);

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['reservationsToMove'])->toBe([[
        'id' => $booking->id,
        'customerName' => 'Aisyah',
        'startsAt' => '2026-10-07T10:00:00+00:00',
        'closedLaneNumbers' => [4],
    ]]);
});

test('a reservation that has started with nobody checked in is late, with when it becomes a no-show', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    $booking = reserveLanes(Lane::factory()->create(['number' => 2]), venueTime('2026-10-07 14:00'), name: 'Bala');
    reserveLanes(Lane::factory()->create(), venueTime('2026-10-07 18:00'), name: 'Still to come');
    $this->travelTo(venueTime('2026-10-07 14:05'));

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['lateReservations'])->toBe([[
        'id' => $booking->id,
        'customerName' => 'Bala',
        'startsAt' => '2026-10-07T06:00:00+00:00',
        'noShowAt' => '2026-10-07T06:15:00+00:00',
        'laneNumbers' => [2],
    ]]);
});

test('a reservation that has checked in is not late', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    $booking = reserveLanes(Lane::factory()->create(), venueTime('2026-10-07 14:00'));
    $this->travelTo(venueTime('2026-10-07 14:05'));
    app(BookingService::class)->checkIn($booking);

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['lateReservations'])->toBe([]);
});

test('requests waiting are counted, with how many are good to call now', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    BookingRequest::factory()->create();
    BookingRequest::factory()->callBetween('13:00', '15:00')->create();

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['requests'])->toBe(['waiting' => 2, 'callNow' => 1]);
});

test('a lane out of order is listed, and is overdue once its estimate has passed', function (string $now, bool $overdue) {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    app(LaneClosures::class)->closeForRepair(Lane::factory()->create(['number' => 6]), days: 1);
    Lane::factory()->create();
    $this->travelTo(venueTime($now));

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['closedLanes'])->toBe([[
        'number' => 6,
        'reason' => 'repair',
        'until' => '2026-10-08T06:00:00+00:00',
        'overdue' => $overdue,
    ]]);
})->with([
    'before the estimate' => ['2026-10-07 20:00', false],
    'after the estimate' => ['2026-10-09 14:00', true],
]);

test('sessions are counted by how the party came, on the day and hour of the venue\'s own clock', function () {
    // Half past midnight on the 7th at the venue is still the 6th in UTC.
    $this->travelTo(venueTime('2026-10-07 00:30'));
    $lane = Lane::factory()->create();
    joinWaitlist(minutes: 60, partySize: 4);
    $waitlist = app(WaitlistService::class);
    $waitlist->seat($waitlist->callNextParties()[0]);
    $this->travelTo(venueTime('2026-10-07 20:00'));
    app(BookingService::class)->checkIn(reserveLanes($lane, venueTime('2026-10-07 20:00'), minutes: 90, partySize: 5));

    $analytics = app(StaffDashboard::class)->analytics(7);

    expect($analytics['sessions'])->toBe(['walkIns' => 1, 'reservations' => 1])
        ->and($analytics['people'])->toBe(9)
        ->and($analytics['laneMinutes'])->toBe(150)
        ->and($analytics['byDay'])->toHaveCount(7)
        ->and($analytics['byDay'][0])->toBe(['date' => '2026-10-01', 'walkIns' => 0, 'reservations' => 0])
        ->and($analytics['byDay'][6])->toBe(['date' => '2026-10-07', 'walkIns' => 1, 'reservations' => 1])
        ->and($analytics['byHour'])->toHaveCount(21)
        ->and($analytics['byHour'][0])->toBe(['hour' => 0, 'sessions' => 1])
        ->and($analytics['byHour'][1])->toBe(['hour' => 1, 'sessions' => 0])
        ->and($analytics['byHour'][20])->toBe(['hour' => 20, 'sessions' => 1]);
});

test('no-shows and cancelled reservations are counted, and a walk-in that missed its call is neither', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    [$first, $second] = Lane::factory()->count(2)->create();
    reserveLanes($first, venueTime('2026-10-07 14:00'));
    app(BookingService::class)->cancel(reserveLanes($second, venueTime('2026-10-07 20:00')));
    $this->travelTo(venueTime('2026-10-07 14:16'));
    app(BookingCleanup::class)->markNoShows();
    joinWaitlist();
    $waitlist = app(WaitlistService::class);
    $waitlist->skip($waitlist->callNextParties()[0]);

    $analytics = app(StaffDashboard::class)->analytics(1);

    expect($analytics['noShows'])->toBe(1)
        ->and($analytics['cancelled'])->toBe(1)
        ->and($analytics['sessions'])->toBe(['walkIns' => 0, 'reservations' => 0]);
});

test('the waitlist numbers say how groups joined, what became of them and how long they waited', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    Lane::factory()->count(2)->create();
    $seated = joinWaitlist(name: 'Seated');
    $missed = joinWaitlist(name: 'Missed its call');
    $this->travelTo(venueTime('2026-10-07 14:10'));
    $waitlist = app(WaitlistService::class);
    $waitlist->callNextParties();
    $waitlist->seat($seated);
    $waitlist->skip($missed);
    $waitlist->leave(joinWaitlistOnline(name: 'Left the line'));

    $analytics = app(StaffDashboard::class)->analytics(1);

    expect($analytics['waitlist'])->toBe([
        'joined' => 3,
        'joinedOnline' => 1,
        'missedCalls' => 1,
        'left' => 1,
        'averageWaitMinutes' => 10,
    ]);
});

test('only the days asked for are counted', function (int $days, int $sessions) {
    $this->travelTo(venueTime('2026-10-01 14:00'));
    laneInPlay();
    $this->travelTo(venueTime('2026-10-09 14:00'));

    $analytics = app(StaffDashboard::class)->analytics($days);

    expect($analytics['sessions']['reservations'])->toBe($sessions);
})->with([
    'today alone' => [1, 0],
    'the last seven days' => [7, 0],
    'the last thirty days' => [30, 1],
]);

test('the busiest times count the sessions of each weekday and hour, and how many of each weekday the days hold', function () {
    $this->travelTo(venueTime('2026-10-03 19:00'));
    laneInPlay();
    $this->travelTo(venueTime('2026-10-10 19:00'));
    laneInPlay();
    $this->travelTo(venueTime('2026-10-10 20:00'));
    laneInPlay();
    $this->travelTo(venueTime('2026-10-14 14:00'));

    $byWeekday = app(StaffDashboard::class)->analytics(30)['byWeekday'];

    // From Tuesday 15 September: four Saturdays and five Wednesdays.
    expect(array_column($byWeekday, 'weekday'))->toBe([1, 2, 3, 4, 5, 6, 0])
        ->and($byWeekday[5])->toBe(['weekday' => 6, 'days' => 4, 'sessions' => [2, 1]])
        ->and($byWeekday[2])->toBe(['weekday' => 3, 'days' => 5, 'sessions' => [0, 0]]);
});

test('time out of order adds up each lane\'s repairs and short jobs within the days covered and up to now', function () {
    $this->travelTo(venueTime('2026-10-07 12:00'));
    $longRepair = Lane::factory()->create(['number' => 2]);
    // A repair that began before the seven days is only counted from their start.
    LaneRepair::factory()->for($longRepair)->create(['started_at' => venueTime('2026-09-29 00:00')->utc(), 'ended_at' => venueTime('2026-10-02 00:00')->utc()]);
    LaneRepair::factory()->for($longRepair)->create(['started_at' => venueTime('2026-10-05 10:00')->utc(), 'ended_at' => venueTime('2026-10-06 10:00')->utc()]);
    app(LaneClosures::class)->closeForRepair(Lane::factory()->create(['number' => 5]));
    Lane::factory()->create(['number' => 6]);
    $this->travelTo(venueTime('2026-10-07 13:00'));
    app(LaneClosures::class)->closeForWork(Lane::factory()->create(['number' => 7]), LaneClosureReason::ReOil, 30);
    $this->travelTo(venueTime('2026-10-07 14:00'));

    $closedTime = app(StaffDashboard::class)->analytics(7)['closedTime'];

    expect($closedTime)->toBe([
        ['number' => 2, 'repairMinutes' => 2880, 'workMinutes' => 0],
        ['number' => 5, 'repairMinutes' => 120, 'workMinutes' => 0],
        ['number' => 7, 'repairMinutes' => 0, 'workMinutes' => 30],
    ]);
});

test('right now counts the lanes by what they are doing, and the groups in line', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    laneInPlay();
    Lane::factory()->create(['number' => 2]);
    joinWaitlist(name: 'Called to lane 2');
    app(WaitlistService::class)->callNextParties();
    joinWaitlist(name: 'Still waiting');
    Lane::factory()->create(['number' => 3]);
    Lane::factory()->outOfOrder()->create(['number' => 4]);
    app(LaneClosures::class)->closeForWork(Lane::factory()->create(['number' => 5]), LaneClosureReason::ReOil, 30);
    reserveLanes(Lane::factory()->create(['number' => 6]), venueTime('2026-10-07 14:30'));

    $rightNow = app(StaffDashboard::class)->rightNow();

    expect($rightNow)->toBe([
        'lanes' => [
            'total' => 6,
            'free' => 1,
            'inPlay' => 1,
            'heldForParty' => 1,
            'outOfOrder' => 1,
            'maintenance' => 1,
            'keptClear' => 1,
        ],
        'nextSessionEndsAt' => '2026-10-07T07:00:00+00:00',
        'line' => ['waiting' => 1, 'called' => 1],
    ]);
});

test('a chosen span counts only the days from its first to its last', function () {
    foreach (['2026-08-31 14:00', '2026-09-15 14:00', '2026-10-01 14:00'] as $moment) {
        $this->travelTo(venueTime($moment));
        laneInPlay();
    }
    $this->travelTo(venueTime('2026-10-15 14:00'));

    $analytics = app(StaffDashboard::class)->analyticsBetween('2026-09-01', '2026-09-30');

    expect($analytics['sessions']['reservations'])->toBe(1)
        ->and($analytics['days'])->toBe(30)
        ->and($analytics['byDay'][0]['date'])->toBe('2026-09-01')
        ->and($analytics['byDay'][29]['date'])->toBe('2026-09-30');
});

test('needs attention says while joining the waitlist online is closed', function () {
    app(WaitlistSettings::class)->setOnlineJoining(false, null);

    $attention = app(StaffDashboard::class)->attention();

    expect($attention['onlineJoiningClosed'])->toBeTrue();
});
