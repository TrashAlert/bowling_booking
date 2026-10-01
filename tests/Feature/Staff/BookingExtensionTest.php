<?php

use App\Models\Booking;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * A reservation on the given lane that has checked in and is playing now.
 */
function runningSession(Lane $lane, string $name = 'Farah'): Booking
{
    return app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60, name: $name));
}

test('staff can extend a session that is still running', function (int $minutes, string $message) {
    $booking = runningSession(Lane::factory()->create());

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.bookings.extend', $booking), ['minutes' => $minutes]);

    $response->assertRedirect(route('staff.board'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
    expect($booking->refresh()->minutes)->toBe(60 + $minutes)
        ->and($booking->allocations()->sole()->ends_at->toDateTimeString())->toBe(now()->addMinutes(60 + $minutes)->toDateTimeString());
})->with([
    'by half an hour' => [30, "Farah's session was extended by 30 minutes."],
    'by an hour and a half' => [90, "Farah's session was extended by 1 hour 30 minutes."],
]);

test('the extra time must be in half-hour steps within the limits', function (mixed $minutes, string $message) {
    $booking = runningSession(Lane::factory()->create());

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.bookings.extend', $booking), ['minutes' => $minutes]);

    $response->assertSessionHasErrors(['minutes' => $message]);
    expect($booking->refresh()->minutes)->toBe(60);
})->with([
    'missing' => [null, 'The minutes field is required.'],
    'no time at all' => [0, 'The minutes field must be at least 30.'],
    'not a half-hour step' => [45, 'The minutes field must be a multiple of 30.'],
    'more than four hours' => [270, 'The minutes field must not be greater than 240.'],
]);

test('staff are told which lane is booked too soon after and nothing changes', function () {
    $lane = Lane::factory()->create(['number' => 7]);
    $booking = runningSession($lane);
    reserveLanes($lane, now()->addMinutes(150));

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.bookings.extend', $booking), ['minutes' => 60]);

    $response->assertSessionHasErrors([
        'minutes' => 'Lane 7 is booked too soon after this session to extend it that long.',
    ]);
    expect($booking->refresh()->minutes)->toBe(60);
});

test('staff are told when a session has already ended', function () {
    $booking = runningSession(Lane::factory()->create());
    $this->travel(61)->minutes();

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.bookings.extend', $booking), ['minutes' => 30]);

    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Only a session that is still running can be extended.',
    ]);
    expect($booking->refresh()->minutes)->toBe(60);
});

test('extending a booking that does not exist returns 404', function () {
    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.bookings.extend', 999999), ['minutes' => 30]);

    $response->assertNotFound();
});
