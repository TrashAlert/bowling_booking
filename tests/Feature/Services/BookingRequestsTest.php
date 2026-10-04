<?php

use App\Enums\BookingRequestStatus;
use App\Enums\BookingStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingRequests;

beforeEach(function () {
    $this->travelTo(venueTime('2026-10-05 15:00'));
});

/**
 * Confirm a request as the reservation it asked for, on the given lanes.
 *
 * @param  array<int, Lane>  $lanes
 */
function confirmRequest(BookingRequest $request, array $lanes, ?User $by = null): Booking
{
    return app(BookingRequests::class)->confirm(
        $request,
        $request->name,
        $request->phone,
        collect($lanes),
        $request->minutes,
        $request->party_size,
        $request->starts_at,
        $request->notes,
        $by ?? User::factory()->staff()->create(),
    );
}

test('confirming a request makes the reservation and marks the request done', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $staff = User::factory()->staff()->create();
    $request = BookingRequest::factory()->create(['name' => 'Farah', 'party_size' => 4, 'minutes' => 90]);

    $booking = confirmRequest($request, [$lane], $staff);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->customer->name)->toBe('Farah')
        ->and($booking->allocations->pluck('lane.number')->all())->toBe([3]);
    expect($request->refresh()->status)->toBe(BookingRequestStatus::Confirmed)
        ->and($request->booking_id)->toBe($booking->id)
        ->and($request->handled_by)->toBe($staff->id)
        ->and($request->handled_at->toIso8601String())->toBe(now()->toIso8601String());
});

test('a request is still waiting when its lane has been taken meanwhile', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $request = BookingRequest::factory()->create();
    reserveLanes($lane, $request->starts_at);

    expect(fn () => confirmRequest($request, [$lane]))->toThrow(NoLaneAvailableException::class);

    expect($request->refresh()->status)->toBe(BookingRequestStatus::Pending)
        ->and($request->booking_id)->toBeNull();
    $this->assertDatabaseCount('bookings', 1);
});

test('a request can only be dealt with once', function (Closure $first) {
    $lane = Lane::factory()->create();
    $request = BookingRequest::factory()->create();
    $first($request);

    expect(fn () => app(BookingRequests::class)->decline($request, null, User::factory()->staff()->create()))
        ->toThrow(InvalidStateException::class, 'This request has already been dealt with.')
        ->and(fn () => confirmRequest($request, [$lane]))
        ->toThrow(InvalidStateException::class, 'This request has already been dealt with.');
})->with([
    'after it was confirmed' => [fn (BookingRequest $request) => confirmRequest($request, [Lane::factory()->create()])],
    'after it was declined' => [fn (BookingRequest $request) => app(BookingRequests::class)->decline($request, null, User::factory()->staff()->create())],
]);

test('declining a request keeps the reason for staff', function () {
    $staff = User::factory()->staff()->create();
    $request = BookingRequest::factory()->create();

    app(BookingRequests::class)->decline($request, 'Fully booked that evening', $staff);

    expect($request->refresh()->only(['status', 'decline_reason', 'handled_by']))->toBe([
        'status' => BookingRequestStatus::Declined,
        'decline_reason' => 'Fully booked that evening',
        'handled_by' => $staff->id,
    ]);
});

test('waiting requests are listed soonest first, and missed ones move to the other list', function () {
    BookingRequest::factory()->create(['name' => 'Later', 'starts_at' => now()->addDays(3)]);
    BookingRequest::factory()->create(['name' => 'Sooner', 'starts_at' => now()->addHours(3)]);
    BookingRequest::factory()->create(['name' => 'Missed', 'starts_at' => now()->subHour()]);
    $declined = BookingRequest::factory()->create(['name' => 'Declined']);
    app(BookingRequests::class)->decline($declined, null, User::factory()->staff()->create());

    $requests = app(BookingRequests::class);

    expect(array_column($requests->waiting(), 'name'))->toBe(['Sooner', 'Later'])
        ->and(array_column($requests->handled(), 'status', 'name'))->toBe(['Declined' => 'declined', 'Missed' => 'missed'])
        ->and($requests->waitingCount())->toBe(2);
});

test('a request is flagged while it is a good time to call the customer', function (string $from, string $until, bool $callNow) {
    // It is 3pm in the venue.
    BookingRequest::factory()->callBetween($from, $until)->create();

    expect(app(BookingRequests::class)->waiting()[0]['callNow'])->toBe($callNow);
})->with([
    'inside the times given' => ['14:00', '18:00', true],
    'before them' => ['16:00', '18:00', false],
    'after them' => ['09:00', '15:00', false],
]);

test('a customer who can be called any time is not flagged', function () {
    BookingRequest::factory()->create();

    $row = app(BookingRequests::class)->waiting()[0];

    expect($row['callNow'])->toBeFalse()
        ->and($row['contactFrom'])->toBeNull()
        ->and($row['contactUntil'])->toBeNull();
});
