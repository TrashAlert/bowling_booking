<?php

use App\Enums\BookingStatus;
use App\Enums\LaneStatus;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WaitlistService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

describe('moving a reservation to other lanes', function () {
    test('staff can move a group off a closed lane', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes([$three, $four], now(), name: 'Farah');
        $three->update(['status' => LaneStatus::OutOfOrder]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->from(route('staff.board'))
            ->patch(route('staff.reservations.lanes.update', $booking), ['lane_ids' => [$four->id, $five->id]]);

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Farah's reservation is now on lanes 4 and 5."]);
        expect($booking->allocations()->pluck('lane_id')->sort()->values()->all())->toBe([$four->id, $five->id]);
    });

    test('a group left with one lane is told so', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $booking = reserveLanes([$three, $four], now(), name: 'Farah');
        $three->update(['status' => LaneStatus::OutOfOrder]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.lanes.update', $booking), ['lane_ids' => [$four->id]]);

        $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Farah's reservation is now on lane 4."]);
    });

    test('at least one open lane must be ticked', function (Closure $laneIds, string $field, string $message) {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now());
        $lane->update(['status' => LaneStatus::OutOfOrder]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.lanes.update', $booking), ['lane_ids' => $laneIds($lane)]);

        $response->assertSessionHasErrors([$field => $message]);
        expect($booking->allocations()->sole()->lane_id)->toBe($lane->id);
    })->with([
        'no lanes ticked' => [fn () => [], 'lane_ids', 'Pick at least one lane.'],
        'the closed lane ticked' => [fn (Lane $lane) => [$lane->id], 'lane_ids.0', 'One of the lanes is closed or no longer exists.'],
    ]);

    test('staff are told when the new lane is not free and nothing moves', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes($three, now());
        reserveLanes($five, now());
        $three->update(['status' => LaneStatus::OutOfOrder]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.lanes.update', $booking), ['lane_ids' => [$five->id]]);

        $response->assertSessionHasErrors(['lane_ids' => 'Lane 5 is not free for that time. Pick another lane.']);
        expect($booking->allocations()->sole()->lane_id)->toBe($three->id);
    });

    test('staff are told when the reservation can no longer be moved', function () {
        $lane = Lane::factory()->create();
        $other = Lane::factory()->create();
        $booking = reserveLanes($lane, now());
        app(BookingService::class)->cancel($booking);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.lanes.update', $booking), ['lane_ids' => [$other->id]]);

        $response->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a confirmed reservation that is still to come can be moved.',
        ]);
    });

    test('a walk-in session cannot be moved as if it were a reservation', function () {
        $lane = Lane::factory()->create();
        $entry = joinWaitlist();
        app(WaitlistService::class)->callNextParties();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.lanes.update', $entry->refresh()->booking), ['lane_ids' => [$lane->id]]);

        $response->assertNotFound();
    });
});

test('checking in is refused with a move-first message while a lane is closed', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $booking = reserveLanes($lane, now());
    $lane->update(['status' => LaneStatus::OutOfOrder]);

    $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.bookings.check-in', $booking));

    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Lane 3 is closed. Move the group to another lane before checking in.',
    ]);
    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('closing a lane says how many reservations on it need moving', function (int $reservations, string $message) {
    $lane = Lane::factory()->create(['number' => 3]);

    foreach (range(1, $reservations) as $index) {
        reserveLanes($lane, now()->addHours($index * 3));
    }

    // Neither of these counts: one is cancelled and one is already playing.
    app(BookingService::class)->cancel(reserveLanes($lane, now()->addHours(12)));
    app(BookingService::class)->checkIn(reserveLanes($lane, now()));

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'repair']);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
})->with([
    'one' => [1, 'Lane 3 is out of order. 1 reservation on it needs moving.'],
    'two' => [2, 'Lane 3 is out of order. 2 reservations on it need moving.'],
]);

test('the board can look up which lanes are free for a reservation being moved', function () {
    $three = Lane::factory()->create(['number' => 3]);
    $five = Lane::factory()->create(['number' => 5]);
    $booking = reserveLanes($three, now()->subMinutes(5), minutes: 60);
    $three->update(['status' => LaneStatus::OutOfOrder]);

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.board', [
        'starts_at' => '2026-10-01T17:55:00.000Z',
        'minutes' => 60,
        'booking' => $booking->id,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('laneOptions')
        ->where('reservations.0.closedLaneNumbers', [3])
        ->reloadOnly('laneOptions', fn (Assert $reload) => $reload->where('laneOptions', [
            ['id' => $three->id, 'number' => 3, 'hasBumpers' => false, 'available' => false],
            ['id' => $five->id, 'number' => 5, 'hasBumpers' => false, 'available' => true],
        ])));
});
