<?php

use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('staff can end the session on a lane early', function () {
    $lane = Lane::factory()->create(['number' => 4]);
    $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60, name: 'Farah'));
    $waiting = joinWaitlist();
    $this->travel(20)->minutes();

    $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.lanes.session.end', $lane));

    $response->assertRedirect(route('staff.board'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Farah's session on lane 4 was ended."]);
    expect($booking->refresh()->status)->toBe(BookingStatus::Completed)
        ->and($booking->allocations()->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 18:20:00')
        ->and($waiting->refresh()->status)->toBe(WaitlistStatus::Waiting);
});

test('ending one lane of a bigger party leaves its other lanes playing', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = app(BookingService::class)->checkIn(reserveLanes([$one, $two], now(), minutes: 60));
    $this->travel(20)->minutes();

    $this->actingAs(User::factory()->staff()->create())->delete(route('staff.lanes.session.end', $one));

    expect($booking->refresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->allocations()->where('lane_id', $two->id)->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 19:00:00');
});

test('staff are told when there is no running session on the lane', function () {
    $lane = Lane::factory()->create();
    $booking = reserveLanes($lane, now(), minutes: 60);

    $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.lanes.session.end', $lane));

    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'There is no running session on this lane to end.',
    ]);
    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('ending the session on a lane that does not exist returns 404', function () {
    $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.lanes.session.end', 999999));

    $response->assertNotFound();
});
