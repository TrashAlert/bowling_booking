<?php

use App\Enums\AllocationStatus;
use App\Enums\LaneClosureReason;
use App\Enums\LaneStatus;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\User;
use App\Services\BookingService;
use App\Services\LaneClosures;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

describe('closing a lane for a short job', function () {
    test('staff can close a free lane for re-oiling and it starts at once', function () {
        $lane = Lane::factory()->create(['number' => 5]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 're_oil', 'minutes' => 30]);

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 is closed for re-oil for 30 minutes.']);

        $closure = LaneAllocation::query()->sole();

        expect($closure->closure_reason)->toBe(LaneClosureReason::ReOil)
            ->and($closure->starts_at->toDateTimeString())->toBe('2026-10-01 18:00:00')
            ->and($closure->ends_at->toDateTimeString())->toBe('2026-10-01 18:30:00')
            ->and($lane->refresh()->status)->toBe(LaneStatus::Open);
    });

    test('a closure on a lane in play waits for the session to end', function () {
        $lane = Lane::factory()->create(['number' => 5]);
        app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'maintenance', 'minutes' => 60]);

        $response->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Lane 5 will close for maintenance when the session on it ends.',
        ]);
        $this->assertDatabaseHas('lane_allocations', ['closure_reason' => 'maintenance', 'starts_at' => '2026-10-01 19:00:00']);
    });

    test('staff are told when the lane is not free for that long', function () {
        $lane = Lane::factory()->create(['number' => 5]);
        reserveLanes($lane, now()->addMinutes(90));

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 're_oil', 'minutes' => 45]);

        $response->assertSessionHasErrors([
            'minutes' => "Lane 5 isn't free for 45 minutes then. Pick a shorter time or close it later.",
        ]);
        $this->assertDatabaseMissing('lane_allocations', ['closure_reason' => 're_oil']);
    });
});

describe('closing a lane for repair', function () {
    test('staff can close a lane for repair until it is reopened', function () {
        $lane = Lane::factory()->create(['number' => 5]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'repair']);

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 is out of order.']);

        $lane->refresh();

        expect($lane->status)->toBe(LaneStatus::OutOfOrder)
            ->and($lane->closed_reason)->toBe(LaneClosureReason::Repair)
            ->and($lane->closed_until)->toBeNull();
    });

    test('a repair can carry an estimate in days', function () {
        $lane = Lane::factory()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'repair', 'days' => 3])
            ->assertSessionHasNoErrors();

        expect($lane->refresh()->closed_until->toDateTimeString())->toBe('2026-10-04 18:00:00');
    });

    test('an estimate of "not known" leaves the lane closed with no date', function () {
        $lane = Lane::factory()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'repair', 'days' => 0])
            ->assertSessionHasNoErrors();

        expect($lane->refresh()->status)->toBe(LaneStatus::OutOfOrder)
            ->and($lane->closed_until)->toBeNull();
    });

    test('closing a lane for repair leaves the session on it untouched', function () {
        $lane = Lane::factory()->create();
        $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lanes.closure.store', $lane), ['reason' => 'repair']);

        expect($lane->refresh()->status)->toBe(LaneStatus::OutOfOrder)
            ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Active)
            ->and($booking->allocations()->sole()->ends_at->toDateTimeString())->toBe('2026-10-01 19:00:00');
    });
});

test('a closure is refused when its reason or length is not one of the presets', function (array $payload, string $field, string $message) {
    $lane = Lane::factory()->create();

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.lanes.closure.store', $lane), $payload);

    $response->assertSessionHasErrors([$field => $message]);
    $this->assertDatabaseCount('lane_allocations', 0);
    expect($lane->refresh()->status)->toBe(LaneStatus::Open);
})->with([
    'no reason' => [[], 'reason', 'The reason field is required.'],
    'unknown reason' => [['reason' => 'on_fire', 'minutes' => 30], 'reason', 'The selected reason is invalid.'],
    'short job without a length' => [['reason' => 're_oil'], 'minutes', 'The minutes field is required.'],
    'length that is not a preset' => [['reason' => 're_oil', 'minutes' => 20], 'minutes', 'The selected minutes is invalid.'],
    'estimate that is not a preset' => [['reason' => 'repair', 'days' => 5], 'days', 'The selected days is invalid.'],
]);

describe('adding time to a closure', function () {
    test('staff can add time when the job overruns', function () {
        $lane = Lane::factory()->create(['number' => 5]);
        $closure = app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.lanes.closure.update', $lane), ['minutes' => 15]);

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 stays closed for 15 minutes more.']);
        expect($closure->refresh()->ends_at->toDateTimeString())->toBe('2026-10-01 18:45:00');
    });

    test('staff are told when there is no closure or no room for more time', function (Closure $arrange, string $message) {
        $lane = Lane::factory()->create(['number' => 5]);
        $arrange($lane);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.lanes.closure.update', $lane), ['minutes' => 15]);

        $response->assertSessionHasErrors(['minutes' => $message]);
    })->with([
        'no closure on the lane' => [fn (Lane $lane) => null, 'Lane 5 has no closure to add time to.'],
        'a reservation straight after' => [function (Lane $lane) {
            reserveLanes($lane, now()->addMinutes(90));
            app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        }, 'Lane 5 is booked too soon after to add 15 minutes.'],
    ]);

    test('the extra time must be one of the presets', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.lanes.closure.update', $lane), ['minutes' => 10]);

        $response->assertSessionHasErrors(['minutes' => 'The selected minutes is invalid.']);
    });
});

describe('reopening a lane', function () {
    test('staff can reopen a lane that was closed for repair', function () {
        $lane = Lane::factory()->create(['number' => 5]);
        app(LaneClosures::class)->closeForRepair($lane, 2);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->delete(route('staff.lanes.closure.destroy', $lane));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Lane 5 is open.']);
        expect($lane->refresh()->status)->toBe(LaneStatus::Open)
            ->and($lane->closed_until)->toBeNull();
    });

    test('staff can reopen a lane early from a short closure', function () {
        $lane = Lane::factory()->create();
        $closure = app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        $this->travel(10)->minutes();

        $this->actingAs(User::factory()->staff()->create())
            ->delete(route('staff.lanes.closure.destroy', $lane));

        expect($closure->refresh()->ends_at->toDateTimeString())->toBe('2026-10-01 18:10:00');
    });
});

test('closing a lane that does not exist returns 404', function () {
    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.lanes.closure.store', 999999), ['reason' => 'repair']);

    $response->assertNotFound();
});

test('the board carries the closure presets for the form', function () {
    $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.board'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('closureOptions', ['minutes' => [15, 30, 45, 60], 'repairDays' => [1, 2, 3]]));
});
