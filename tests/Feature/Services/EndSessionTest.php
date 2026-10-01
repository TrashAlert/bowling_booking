<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Services\BookingService;
use App\Services\LaneAvailability;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('ending a session frees its lane at once and completes the booking', function () {
    $lane = Lane::factory()->create();
    $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
    $this->travel(20)->minutes();

    $ended = app(BookingService::class)->endSessionOnLane($lane);

    expect($ended->status)->toBe(BookingStatus::Completed)
        ->and($ended->minutes)->toBe(60)
        ->and($booking->allocations()->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 18:20:00')
        ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Active)
        ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1);
});

test('a party on several lanes keeps the others when one lane ends', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = app(BookingService::class)->checkIn(reserveLanes([$one, $two], now(), minutes: 60));
    $this->travel(20)->minutes();

    $afterFirst = app(BookingService::class)->endSessionOnLane($one);

    expect($afterFirst->status)->toBe(BookingStatus::CheckedIn)
        ->and(app(LaneAvailability::class)->freeLanes(now(), now()->addMinutes(40))->pluck('number')->all())->toBe([1])
        ->and($booking->allocations()->where('lane_id', $two->id)->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 19:00:00');

    $afterLast = app(BookingService::class)->endSessionOnLane($two);

    expect($afterLast->status)->toBe(BookingStatus::Completed);
});

test('the lanes a party kept can still be extended', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = app(BookingService::class)->checkIn(reserveLanes([$one, $two], now(), minutes: 60));
    $this->travel(20)->minutes();
    app(BookingService::class)->endSessionOnLane($one);

    app(BookingService::class)->extend($booking, 30);

    expect($booking->allocations()->where('lane_id', $two->id)->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 19:30:00')
        ->and($booking->allocations()->where('lane_id', $one->id)->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 18:20:00');
});

test('a session ended in the second it began leaves the lane free', function () {
    $lane = Lane::factory()->create();
    $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));

    app(BookingService::class)->endSessionOnLane($lane);

    expect($booking->refresh()->status)->toBe(BookingStatus::Completed)
        ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Released)
        ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1);
});

test('ending a session does not call the waitlist', function () {
    $lane = Lane::factory()->create();
    app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
    $waiting = joinWaitlist(minutes: 30);
    $this->travel(20)->minutes();

    app(BookingService::class)->endSessionOnLane($lane);

    expect($waiting->refresh()->status)->toBe(WaitlistStatus::Waiting);
});

test('a lane with nobody playing on it has no session to end', function (Closure $arrange) {
    $lane = Lane::factory()->create();
    $arrange($lane);

    expect(fn () => app(BookingService::class)->endSessionOnLane($lane))
        ->toThrow(InvalidStateException::class, 'There is no running session on this lane to end.');

    $this->assertDatabaseMissing('bookings', ['status' => BookingStatus::Completed->value]);
})->with([
    'a free lane' => [fn (Lane $lane) => null],
    'a reservation that has not checked in' => [fn (Lane $lane) => reserveLanes($lane, now(), minutes: 60)],
    'a lane closed ahead of a reservation' => [fn (Lane $lane) => reserveLanes($lane, now()->addMinutes(30))],
    'a lane held for a called walk-in' => [function (Lane $lane) {
        joinWaitlist();
        app(WaitlistService::class)->callNextParties();
    }],
    'a session whose time is already up' => [function (Lane $lane) {
        app(BookingService::class)->checkIn(reserveLanes($lane, now()->subHour(), minutes: 60));
    }],
]);
