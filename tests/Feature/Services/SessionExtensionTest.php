<?php

use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\BookingService;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * A walk-in party that has been called and seated, so it is playing now.
 */
function seatWalkIn(int $minutes = 60): Booking
{
    $entry = joinWaitlist(minutes: $minutes);
    app(WaitlistService::class)->callNextParties();

    return app(WaitlistService::class)->seat($entry)->booking;
}

/**
 * When the booking's session ends on each of its lanes, by lane number.
 *
 * @return array<int, string>
 */
function sessionEnds(Booking $booking): array
{
    return $booking->allocations()
        ->with('lane')
        ->get()
        ->mapWithKeys(fn (LaneAllocation $allocation) => [$allocation->lane->number => $allocation->ends_at->format('H:i')])
        ->sortKeys()
        ->all();
}

test('extending a running session adds the time to its lane and its length', function () {
    Lane::factory()->create(['number' => 1]);
    $booking = seatWalkIn(minutes: 60);

    $extended = app(BookingService::class)->extend($booking, 30);

    expect($extended->minutes)->toBe(90)
        ->and(sessionEnds($booking))->toBe([1 => '19:30']);
});

test('every lane of a party is extended together', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = reserveLanes([$one, $two], now(), minutes: 60);
    app(BookingService::class)->checkIn($booking);

    app(BookingService::class)->extend($booking, 60);

    expect($booking->refresh()->minutes)->toBe(120)
        ->and(sessionEnds($booking))->toBe([1 => '20:00', 2 => '20:00']);
});

test('a session can be extended right up to when its lane closes for a reservation', function () {
    $lane = Lane::factory()->create(['number' => 1]);
    $booking = seatWalkIn(minutes: 60);
    reserveLanes($lane, now()->addMinutes(150));

    app(BookingService::class)->extend($booking, 30);

    expect(sessionEnds($booking))->toBe([1 => '19:30']);
});

test('no lane is extended when one of them is booked too soon after', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = reserveLanes([$one, $two], now(), minutes: 60);
    app(BookingService::class)->checkIn($booking);
    reserveLanes($two, now()->addMinutes(150));

    expect(fn () => app(BookingService::class)->extend($booking, 60))
        ->toThrow(function (NoLaneAvailableException $exception) {
            expect($exception->laneNumber)->toBe(2);
        });

    expect($booking->refresh()->minutes)->toBe(60)
        ->and(sessionEnds($booking))->toBe([1 => '19:00', 2 => '19:00']);
});

test('a session whose time has run out cannot be extended', function () {
    Lane::factory()->create(['number' => 1]);
    $booking = seatWalkIn(minutes: 60);
    $this->travel(60)->minutes();

    expect(fn () => app(BookingService::class)->extend($booking, 30))
        ->toThrow(InvalidStateException::class, 'Only a session that is still running can be extended.');

    expect($booking->refresh()->minutes)->toBe(60)
        ->and(sessionEnds($booking))->toBe([1 => '19:00']);
});

test('a session can still be extended in its last minute', function () {
    Lane::factory()->create(['number' => 1]);
    $booking = seatWalkIn(minutes: 60);
    $this->travel(59)->minutes();

    app(BookingService::class)->extend($booking, 30);

    expect(sessionEnds($booking))->toBe([1 => '19:30']);
});

test('a reservation that has not checked in cannot be extended', function () {
    $booking = reserveLanes(Lane::factory()->create(['number' => 1]), now(), minutes: 60);

    expect(fn () => app(BookingService::class)->extend($booking, 30))
        ->toThrow(InvalidStateException::class);

    expect(sessionEnds($booking))->toBe([1 => '19:00']);
});

test('a party that was called but not yet seated cannot be extended', function () {
    Lane::factory()->create(['number' => 1]);
    $entry = joinWaitlist(minutes: 60);
    app(WaitlistService::class)->callNextParties();
    $booking = $entry->refresh()->booking;

    expect(fn () => app(BookingService::class)->extend($booking, 30))
        ->toThrow(InvalidStateException::class);

    expect(sessionEnds($booking))->toBe([1 => '19:00']);
});
