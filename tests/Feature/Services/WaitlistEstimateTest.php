<?php

use App\Enums\LaneStatus;
use App\Models\Lane;
use App\Services\BookingService;
use App\Services\WaitlistEstimate;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * Put a party that has checked in on a lane from now.
 */
function playOn(Lane $lane, int $minutes): void
{
    app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: $minutes));
}

test('a new party has no wait while a lane is free', function () {
    Lane::factory()->create();

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits)->toBe([30 => 0, 60 => 0, 90 => 0, 120 => 0, 150 => 0, 180 => 0, 210 => 0, 240 => 0]);
});

test('a new party waits until the session in play is over', function () {
    playOn(Lane::factory()->create(), minutes: 60);
    $this->travel(20)->minutes();

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits[60])->toBe(40);
});

test('with several lanes a new party waits for the first one to free up', function () {
    playOn(Lane::factory()->create(), minutes: 120);
    playOn(Lane::factory()->create(), minutes: 30);

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits[60])->toBe(30);
});

test('a new party waits behind everyone already in line', function () {
    playOn(Lane::factory()->create(), minutes: 60);
    joinWaitlist(minutes: 90);

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits[60])->toBe(150);
});

test('a session that would run into a reservation waits until after it', function () {
    // The lane closes at 18:30 for a reservation from 19:30 to 20:30.
    reserveLanes(Lane::factory()->create(), now()->addMinutes(90), minutes: 60);

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits[30])->toBe(0)
        ->and($waits[60])->toBe(150);
});

test('there is no estimate while no lane is open', function () {
    Lane::factory()->create(['status' => LaneStatus::OutOfOrder]);

    $waits = app(WaitlistEstimate::class)->forNewParty();

    expect($waits[60])->toBeNull();
});

test('a waiting party is only held up by the parties ahead of it', function () {
    playOn(Lane::factory()->create(), minutes: 60);
    $first = joinWaitlist(minutes: 60);
    $this->travel(1)->minutes();
    $second = joinWaitlist(minutes: 60);
    joinWaitlist(minutes: 240, name: 'Joined last');

    expect(app(WaitlistEstimate::class)->forEntry($first))->toBe(59)
        ->and(app(WaitlistEstimate::class)->forEntry($second))->toBe(119);
});

test('a party that has been called has no estimate', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();

    $wait = app(WaitlistEstimate::class)->forEntry($entry->refresh());

    expect($wait)->toBeNull();
});
