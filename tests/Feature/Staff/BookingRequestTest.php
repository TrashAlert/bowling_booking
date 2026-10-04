<?php

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(venueTime('2026-10-05 15:00'));
});

/**
 * The reservation form as staff send it when confirming a request.
 *
 * @param  array<int, Lane>  $lanes
 * @return array<string, mixed>
 */
function confirmForm(BookingRequest $request, array $lanes): array
{
    return [
        'booking_request_id' => $request->id,
        'name' => $request->name,
        'phone' => $request->phone,
        'party_size' => $request->party_size,
        'starts_at' => $request->starts_at->toIso8601String(),
        'minutes' => $request->minutes,
        'lane_ids' => array_map(fn (Lane $lane) => $lane->id, $lanes),
        'notes' => $request->notes,
    ];
}

test('guests are sent to log in and non-staff are refused', function () {
    $this->get(route('staff.requests.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('staff.requests.index'))->assertForbidden();
});

test('staff see the requests to deal with and the ones already dealt with', function () {
    $waiting = BookingRequest::factory()->create(['name' => 'Farah']);
    BookingRequest::factory()->create(['name' => 'Missed', 'starts_at' => now()->subHour()]);

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.requests.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('staff/requests')
        ->has('waiting', 1, fn (Assert $row) => $row
            ->where('id', $waiting->id)
            ->where('name', 'Farah')
            ->where('status', 'pending')
            ->etc())
        ->has('handled', 1, fn (Assert $row) => $row->where('status', 'missed')->etc())
        ->has('session')
        ->has('limits')
        ->missing('laneOptions'));
});

test('staff pages show how many requests are waiting, and other users do not', function () {
    BookingRequest::factory()->count(2)->create();

    $staff = $this->actingAs(User::factory()->staff()->create())->get(route('staff.board'));
    $other = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $staff->assertInertia(fn (Assert $page) => $page->where('requestsWaiting', 2));
    $other->assertInertia(fn (Assert $page) => $page->where('requestsWaiting', null));
});

test('staff can confirm a request as a reservation on the lanes they pick', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $request = BookingRequest::factory()->create(['name' => 'Farah']);

    $response = $this->actingAs(User::factory()->staff()->create())
        ->from(route('staff.requests.index'))
        ->post(route('staff.reservations.store'), confirmForm($request, [$lane]));

    $response->assertRedirect(route('staff.requests.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah is booked.']);
    expect($request->refresh()->status)->toBe(BookingRequestStatus::Confirmed)
        ->and($request->booking->allocations->pluck('lane.number')->all())->toBe([3]);
});

test('staff are told when the lane was taken meanwhile, and the request stays waiting', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $request = BookingRequest::factory()->create();
    reserveLanes($lane, $request->starts_at);

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.reservations.store'), confirmForm($request, [$lane]));

    $response->assertSessionHasErrors(['lane_ids' => 'Lane 3 is not free for that time. Pick another lane or time.']);
    expect($request->refresh()->status)->toBe(BookingRequestStatus::Pending);
});

test('staff are told when someone else already dealt with the request', function () {
    $lane = Lane::factory()->create();
    $request = BookingRequest::factory()->create(['status' => BookingRequestStatus::Declined]);

    $response = $this->actingAs(User::factory()->staff()->create())
        ->post(route('staff.reservations.store'), confirmForm($request, [$lane]));

    $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This request has already been dealt with.']);
    $this->assertDatabaseCount('bookings', 0);
});

test('staff can decline a request with a reason', function () {
    $staff = User::factory()->staff()->create();
    $request = BookingRequest::factory()->create(['name' => 'Farah']);

    $response = $this->actingAs($staff)
        ->from(route('staff.requests.index'))
        ->delete(route('staff.requests.destroy', $request), ['reason' => 'Fully booked that evening']);

    $response->assertRedirect(route('staff.requests.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah\'s request was declined.']);
    expect($request->refresh()->only(['status', 'decline_reason', 'handled_by']))->toBe([
        'status' => BookingRequestStatus::Declined,
        'decline_reason' => 'Fully booked that evening',
        'handled_by' => $staff->id,
    ]);
});

test('declining a request that does not exist returns 404', function () {
    $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.requests.destroy', 999999));

    $response->assertNotFound();
});
