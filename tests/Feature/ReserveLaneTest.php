<?php

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use Inertia\Testing\AssertableInertia as Assert;

// In the venue's time zone (Kuala Lumpur), 5 October 2026 is a Monday.
beforeEach(function () {
    $this->travelTo(venueTime('2026-10-05 12:00'));
});

/**
 * A valid request: Tuesday 6 October at 7pm in Kuala Lumpur (11:00 UTC) for
 * 90 minutes, with a time to be called.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function requestForm(array $overrides = []): array
{
    return [
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 8,
        'starts_at' => '2026-10-06T11:00:00.000Z',
        'minutes' => 90,
        'call_any_time' => false,
        'contact_from' => '14:00',
        'contact_until' => '18:00',
        'notes' => 'A birthday',
        ...$overrides,
    ];
}

test('anyone can open the reservation page without logging in', function () {
    $response = $this->get(route('reserve'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('reserve/lane')
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
        ->where('limits', ['maxPartySize' => 200, 'maxDaysAhead' => 90])
        ->where('openingHours', null));
});

test('the reservation page gets the opening hours once they are set', function () {
    openDaily('10:00', '23:00', except: [0 => null]);

    $response = $this->get(route('reserve'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('openingHours', 7)
        ->where('openingHours.0', ['weekday' => 1, 'opens' => '10:00', 'closes' => '23:00'])
        ->where('openingHours.6', ['weekday' => 0, 'opens' => null, 'closes' => null]));
});

test('sending a request saves it for staff and books nothing', function () {
    $response = $this->post(route('booking-requests.store'), requestForm());

    $response->assertRedirect(route('booking-requests.thanks'));
    $request = BookingRequest::query()->sole();
    expect($request->only(['name', 'phone', 'party_size', 'minutes', 'contact_from', 'contact_until', 'notes', 'status']))->toBe([
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 8,
        'minutes' => 90,
        'contact_from' => '14:00:00',
        'contact_until' => '18:00:00',
        'notes' => 'A birthday',
        'status' => BookingRequestStatus::Pending,
    ])->and($request->starts_at->toIso8601String())->toBe('2026-10-06T11:00:00+00:00');
    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('lane_allocations', 0);
});

test('a customer who can be called any time leaves the call times empty', function () {
    $this->post(route('booking-requests.store'), requestForm(['call_any_time' => true, 'contact_from' => null, 'contact_until' => null]));

    expect(BookingRequest::query()->sole()->only(['contact_from', 'contact_until']))->toBe(['contact_from' => null, 'contact_until' => null]);
});

test('the thank-you page repeats the request and when we will call', function () {
    $response = $this->followingRedirects()->post(route('booking-requests.store'), requestForm());

    $response->assertInertia(fn (Assert $page) => $page
        ->component('reserve/thanks')
        ->where('request', [
            'name' => 'Farah Aziz',
            'phone' => '0123456789',
            'partySize' => 8,
            'minutes' => 90,
            'startsAt' => '2026-10-06T11:00:00+00:00',
            'contactFrom' => '14:00',
            'contactUntil' => '18:00',
        ]));
});

test('the thank-you page only shows straight after sending', function () {
    BookingRequest::factory()->create();

    $response = $this->get(route('booking-requests.thanks'));

    $response->assertRedirect(route('reserve'));
});

test('the name, phone, start, length, party size and call times are required', function () {
    $response = $this->post(route('booking-requests.store'), ['call_any_time' => false]);

    $response->assertSessionHasErrors([
        'name' => 'The name field is required.',
        'phone' => 'The phone field is required.',
        'party_size' => 'The party size field is required.',
        'starts_at' => 'The start time field is required.',
        'minutes' => 'The minutes field is required.',
        'contact_from' => 'Say from when we can call you, or tick "any time".',
        'contact_until' => 'Say until when we can call you, or tick "any time".',
    ]);
    $this->assertDatabaseCount('booking_requests', 0);
});

test('a request is refused when a detail is not acceptable', function (array $override, string $field, string $message) {
    $response = $this->post(route('booking-requests.store'), requestForm($override));

    $response->assertSessionHasErrors([$field => $message]);
    $this->assertDatabaseCount('booking_requests', 0);
})->with([
    'a phone number with a symbol' => [['phone' => '012-3456789'], 'phone', 'The phone number can only contain digits, with no spaces, letters or symbols.'],
    'a start in the past' => [['starts_at' => '2026-10-05T03:30:00.000Z'], 'starts_at', 'The start time can\'t be in the past.'],
    'a start too far ahead' => [['starts_at' => '2027-02-01T11:00:00.000Z'], 'starts_at', 'Reservations can be made up to 90 days ahead.'],
    'a start off the half hour' => [['starts_at' => '2026-10-06T11:10:00.000Z'], 'starts_at', 'The start time must be on the hour or half hour.'],
    'calling until before calling from' => [['contact_from' => '18:00', 'contact_until' => '14:00'], 'contact_until', 'The end of the time to call must be after its start.'],
    'an absurd party size' => [['party_size' => 201], 'party_size', 'The party size field must not be greater than 200.'],
]);

test('a request must fall within opening hours', function (string $startsAt, int $minutes) {
    openDaily('10:00', '23:00', except: [0 => null]);

    $response = $this->post(route('booking-requests.store'), requestForm(['starts_at' => $startsAt, 'minutes' => $minutes]));

    $response->assertSessionHasErrors([
        'starts_at' => 'We are not open for the whole of that session. Pick another time or a shorter session.',
    ]);
    $this->assertDatabaseCount('booking_requests', 0);
})->with([
    // 01:00 UTC is 9am in Kuala Lumpur.
    'before opening' => ['2026-10-06T01:00:00.000Z', 60],
    // 14:30 UTC is 10:30pm: an hour runs past the 11pm closing.
    'running past closing' => ['2026-10-06T14:30:00.000Z', 60],
    'on a closed day' => ['2026-10-11T06:00:00.000Z', 60],
]);

test('a session that ends exactly at closing time is accepted', function () {
    openDaily('10:00', '23:00');

    $response = $this->post(route('booking-requests.store'), requestForm(['starts_at' => '2026-10-06T14:00:00.000Z', 'minutes' => 60]));

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseCount('booking_requests', 1);
});

test('sending requests is refused after five in a minute', function () {
    foreach (range(1, 5) as $try) {
        $this->post(route('booking-requests.store'), requestForm());
    }

    $response = $this->post(route('booking-requests.store'), requestForm());

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('booking_requests', 5);
});
