<?php

use App\Enums\AllocationStatus;
use App\Enums\LaneStatus;
use App\Models\Lane;
use App\Models\User;

test('staff can mark a lane out of order', function () {
    $lane = Lane::factory()->create(['number' => 5]);

    $response = $this->actingAs(User::factory()->staff()->create())->patch(route('staff.lanes.update', $lane), [
        'status' => 'out_of_order',
    ]);

    $response->assertRedirect(route('staff.board'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 is out of order.']);
    expect($lane->refresh()->status)->toBe(LaneStatus::OutOfOrder);
});

test('staff can reopen a lane', function () {
    $lane = Lane::factory()->outOfOrder()->create(['number' => 5]);

    $response = $this->actingAs(User::factory()->staff()->create())->patch(route('staff.lanes.update', $lane), [
        'status' => 'open',
    ]);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 is open.']);
    expect($lane->refresh()->status)->toBe(LaneStatus::Open);
});

test('marking a lane out of order leaves the session on it untouched', function () {
    $lane = Lane::factory()->create();
    $booking = bookReservation(now());

    $this->actingAs(User::factory()->staff()->create())->patch(route('staff.lanes.update', $lane), [
        'status' => 'out_of_order',
    ]);

    expect($lane->refresh()->status)->toBe(LaneStatus::OutOfOrder)
        ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Active);
});

test('a lane cannot be given an unknown status', function () {
    $lane = Lane::factory()->create();

    $response = $this->actingAs(User::factory()->staff()->create())->patch(route('staff.lanes.update', $lane), [
        'status' => 'on_fire',
    ]);

    $response->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
    expect($lane->refresh()->status)->toBe(LaneStatus::Open);
});
