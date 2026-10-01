<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WaitlistService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00:00');
});

/**
 * A valid reservation form for the given lanes, two hours from now.
 *
 * @param  array<int, Lane>  $lanes
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reservationForm(array $lanes, array $overrides = []): array
{
    return [
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 14,
        'starts_at' => '2026-10-01T12:00:00.000Z',
        'minutes' => 90,
        'lane_ids' => array_map(fn (Lane $lane) => $lane->id, $lanes),
        'notes' => 'Birthday',
        ...$overrides,
    ];
}

describe('the reservations page', function () {
    test('staff see the reservations of a day in their own time zone', function () {
        $lane = Lane::factory()->create(['number' => 3]);
        // 17:00 UTC on 1 October is 01:00 on 2 October in Kuala Lumpur.
        $booking = reserveLanes($lane, now()->addHours(7), name: 'Night owls');
        $staff = User::factory()->staff()->create();

        $nextDay = $this->actingAs($staff)->get(route('staff.reservations.index', ['date' => '2026-10-02', 'tz' => 'Asia/Kuala_Lumpur']));
        $sameDay = $this->actingAs($staff)->get(route('staff.reservations.index', ['date' => '2026-10-01', 'tz' => 'Asia/Kuala_Lumpur']));

        $nextDay->assertInertia(fn (Assert $page) => $page
            ->component('staff/reservations')
            ->where('date', '2026-10-02')
            ->has('reservations', 1, fn (Assert $row) => $row
                ->where('id', $booking->id)
                ->where('customerName', 'Night owls')
                ->where('lanes', [['id' => $lane->id, 'number' => 3]])
                ->etc())
            ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
            ->where('search', null)
            ->where('limits', ['maxPartySize' => 200, 'leadMinutes' => 60, 'maxDaysAhead' => 90, 'searchLimit' => 50])
            ->where('serverNow', '2026-10-01T10:00:00+00:00')
            ->missing('laneOptions'));
        $sameDay->assertInertia(fn (Assert $page) => $page->has('reservations', 0));
    });

    test('without a date the page shows today in the given time zone', function () {
        // 10:00 UTC on 1 October is already 2 October on Kiritimati (UTC+14).
        $response = $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.reservations.index', ['tz' => 'Pacific/Kiritimati']));

        $response->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-02'));
    });

    test('a search lists the matching reservations of every day in place of the day', function () {
        $lane = Lane::factory()->create();
        $match = reserveLanes($lane, now()->addDays(5), name: 'Farah Aziz');
        reserveLanes($lane, now()->addHours(3), name: 'Daniel Lee');

        $response = $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.reservations.index', ['date' => '2026-10-01', 'tz' => 'UTC', 'search' => 'farah']));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('search', 'farah')
            ->has('reservations', 1, fn (Assert $row) => $row
                ->where('id', $match->id)
                ->where('customerName', 'Farah Aziz')
                ->etc()));
    });

    test('a search term of more than 100 characters is rejected', function () {
        $response = $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.reservations.index', ['search' => str_repeat('a', 101)]));

        $response->assertSessionHasErrors(['search' => 'The search field must not be greater than 100 characters.']);
    });

    test('an unknown time zone is rejected', function () {
        $response = $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.reservations.index', ['tz' => 'Mars/Olympus_Mons']));

        $response->assertSessionHasErrors('tz');
    });

    test('the form can ask which lanes are free for a start time and length', function () {
        $free = Lane::factory()->create(['number' => 1]);
        $taken = Lane::factory()->withBumpers()->create(['number' => 2]);
        reserveLanes($taken, now()->addHours(2));

        $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.reservations.index', [
            'starts_at' => '2026-10-01T12:00:00.000Z',
            'minutes' => 60,
        ]));

        $response->assertInertia(fn (Assert $page) => $page->reloadOnly('laneOptions', fn (Assert $reload) => $reload
            ->where('laneOptions', [
                ['id' => $free->id, 'number' => 1, 'hasBumpers' => false, 'available' => true],
                ['id' => $taken->id, 'number' => 2, 'hasBumpers' => true, 'available' => false],
            ])));
    });

    test('a reservation being changed sees its own lanes as free', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(2));

        $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.reservations.index', [
            'starts_at' => '2026-10-01T12:00:00.000Z',
            'minutes' => 120,
            'booking' => $booking->id,
        ]));

        $response->assertInertia(fn (Assert $page) => $page->reloadOnly('laneOptions', fn (Assert $reload) => $reload
            ->where('laneOptions.0.available', true)));
    });
});

describe('making a reservation', function () {
    test('staff can reserve several lanes for a big party', function () {
        $one = Lane::factory()->create(['number' => 1]);
        $two = Lane::factory()->create(['number' => 2]);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->from(route('staff.reservations.index', ['date' => '2026-10-01']))
            ->post(route('staff.reservations.store'), reservationForm([$one, $two]));

        $response->assertRedirect(route('staff.reservations.index', ['date' => '2026-10-01']))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah Aziz is booked.']);

        $booking = Booking::query()->sole();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->source)->toBe(BookingSource::Phone)
            ->and($booking->party_size)->toBe(14)
            ->and($booking->minutes)->toBe(90)
            ->and($booking->notes)->toBe('Birthday')
            ->and($booking->customer->name)->toBe('Farah Aziz')
            ->and($booking->customer->phone)->toBe('0123456789')
            ->and($booking->allocations->pluck('lane_id')->sort()->values()->all())->toBe([$one->id, $two->id])
            ->and($booking->allocations->first()->starts_at->toDateTimeString())->toBe('2026-10-01 12:00:00')
            ->and($booking->allocations->first()->ends_at->toDateTimeString())->toBe('2026-10-01 13:30:00')
            ->and($booking->closures)->toHaveCount(2);
    });

    test('the name, phone, party size, start time, length and lanes are required', function () {
        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.reservations.store'), []);

        $response->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'phone' => 'The phone field is required.',
            'party_size' => 'The party size field is required.',
            'starts_at' => 'The start time field is required.',
            'minutes' => 'The minutes field is required.',
            'lane_ids' => 'Pick at least one lane.',
        ]);
        $this->assertDatabaseCount('bookings', 0);
    });

    test('a reservation is refused when a detail is not acceptable', function (array $override, string $field, string $message) {
        $lane = Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.reservations.store'), reservationForm([$lane], $override));

        $response->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('lane_allocations', 0);
    })->with([
        'start in the past' => [['starts_at' => '2026-10-01T09:30:00.000Z'], 'starts_at', "The start time can't be in the past."],
        'start too far ahead' => [['starts_at' => '2026-12-31T12:00:00.000Z'], 'starts_at', 'Reservations can be made up to 90 days ahead.'],
        'start off the half hour' => [['starts_at' => '2026-10-01T12:10:00.000Z'], 'starts_at', 'The start time must be on the hour or half hour.'],
        'start not a date' => [['starts_at' => 'tomorrow-ish'], 'starts_at', 'The start time field must be a valid date.'],
        'length off the half hour' => [['minutes' => 45], 'minutes', 'The minutes field must be a multiple of 30.'],
        'nobody in the party' => [['party_size' => 0], 'party_size', 'The party size field must be at least 1.'],
        'an absurd party size' => [['party_size' => 201], 'party_size', 'The party size field must not be greater than 200.'],
        'no lanes ticked' => [['lane_ids' => []], 'lane_ids', 'Pick at least one lane.'],
        'a lane that does not exist' => [['lane_ids' => [999999]], 'lane_ids.0', 'One of the lanes is closed or no longer exists.'],
        'notes too long' => [['notes' => str_repeat('a', 501)], 'notes', 'The notes field must not be greater than 500 characters.'],
    ]);

    test('a lane that is out of order cannot be ticked', function () {
        $lane = Lane::factory()->outOfOrder()->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.reservations.store'), reservationForm([$lane]));

        $response->assertSessionHasErrors(['lane_ids.0' => 'One of the lanes is closed or no longer exists.']);
        $this->assertDatabaseCount('bookings', 0);
    });

    test('a party of more than six needs no extra lanes', function () {
        $lane = Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.reservations.store'), reservationForm([$lane], ['party_size' => 20]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('bookings', ['party_size' => 20]);
    });

    test('staff are told which lane was taken in the meantime and nothing is saved', function () {
        $free = Lane::factory()->create(['number' => 1]);
        $taken = Lane::factory()->create(['number' => 2]);
        reserveLanes($taken, now()->addHours(2));

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.reservations.store'), reservationForm([$free, $taken]));

        $response->assertSessionHasErrors([
            'lane_ids' => 'Lane 2 is not free for that time. Pick another lane or time.',
        ]);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseMissing('lane_allocations', ['lane_id' => $free->id]);
    });
});

describe('changing a reservation', function () {
    test('staff can move a reservation and change its details', function () {
        $old = Lane::factory()->create(['number' => 1]);
        $new = Lane::factory()->create(['number' => 2]);
        $booking = reserveLanes($old, now()->addHours(2), name: 'Farah');

        $response = $this->actingAs(User::factory()->staff()->create())->patch(
            route('staff.reservations.update', $booking),
            reservationForm([$new], ['name' => 'Farah Aziz', 'starts_at' => '2026-10-01T15:00:00.000Z', 'minutes' => 120]),
        );

        $response->assertRedirect(route('staff.reservations.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The reservation was updated.']);

        $booking->refresh();

        expect($booking->minutes)->toBe(120)
            ->and($booking->party_size)->toBe(14)
            ->and($booking->customer->name)->toBe('Farah Aziz')
            ->and($booking->allocations()->sole()->lane_id)->toBe($new->id)
            ->and($booking->allocations()->sole()->starts_at->toDateTimeString())->toBe('2026-10-01 15:00:00');
        $this->assertDatabaseMissing('lane_allocations', ['lane_id' => $old->id]);
    });

    test('a change is refused and nothing moves when the new lane is not free', function () {
        $lane = Lane::factory()->create(['number' => 1]);
        $other = Lane::factory()->create(['number' => 2]);
        $booking = reserveLanes($lane, now()->addHours(2), name: 'Farah');
        reserveLanes($other, now()->addHours(5));

        $response = $this->actingAs(User::factory()->staff()->create())->patch(
            route('staff.reservations.update', $booking),
            reservationForm([$other], ['starts_at' => '2026-10-01T15:00:00.000Z', 'minutes' => 60]),
        );

        $response->assertSessionHasErrors([
            'lane_ids' => 'Lane 2 is not free for that time. Pick another lane or time.',
        ]);
        expect($booking->refresh()->customer->name)->toBe('Farah')
            ->and($booking->allocations()->sole()->lane_id)->toBe($lane->id);
    });

    test('staff are told when a reservation can no longer be changed', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(2));
        app(BookingService::class)->cancel($booking);

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.update', $booking), reservationForm([$lane]));

        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a confirmed reservation can be changed.']);
        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });

    test('a walk-in session cannot be changed as if it were a reservation', function () {
        $lane = Lane::factory()->create();
        $entry = joinWaitlist();
        app(WaitlistService::class)->callNextParties();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->patch(route('staff.reservations.update', $entry->refresh()->booking), reservationForm([$lane]));

        $response->assertNotFound();
    });
});

describe('cancelling a reservation', function () {
    test('staff can cancel a reservation', function () {
        $booking = reserveLanes(Lane::factory()->create(), now()->addHours(2), name: 'Farah');

        $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.reservations.destroy', $booking));

        $response->assertRedirect(route('staff.reservations.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Farah's reservation was cancelled."]);
        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });

    test('staff are told when a reservation can no longer be cancelled', function () {
        $booking = reserveLanes(Lane::factory()->create(), now());
        app(BookingService::class)->checkIn($booking);

        $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.reservations.destroy', $booking));

        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This booking can no longer be cancelled.']);
        expect($booking->refresh()->status)->toBe(BookingStatus::CheckedIn);
    });

    test('a walk-in session cannot be cancelled as if it were a reservation', function () {
        Lane::factory()->create();
        $entry = joinWaitlist();
        app(WaitlistService::class)->callNextParties();
        $booking = $entry->refresh()->booking;

        $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.reservations.destroy', $booking));

        $response->assertNotFound();
        expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
    });
});

test('checking in from the reservations page returns there', function () {
    $booking = reserveLanes(Lane::factory()->create(), now());

    $response = $this->actingAs(User::factory()->staff()->create())
        ->from(route('staff.reservations.index', ['date' => '2026-10-01']))
        ->post(route('staff.bookings.check-in', $booking));

    $response->assertRedirect(route('staff.reservations.index', ['date' => '2026-10-01']));
    expect($booking->refresh()->status)->toBe(BookingStatus::CheckedIn);
});
