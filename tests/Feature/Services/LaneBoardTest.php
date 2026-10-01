<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Models\Customer;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\BookingService;
use App\Services\LaneBoard;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('a lane with nothing on it is free', function () {
    Lane::factory()->withBumpers()->create(['number' => 3]);

    $lanes = app(LaneBoard::class)->lanes();

    expect($lanes)->toHaveCount(1)
        ->and($lanes[0])->toMatchArray([
            'number' => 3,
            'hasBumpers' => true,
            'isOpen' => true,
            'state' => 'free',
            'current' => null,
            'next' => null,
        ]);
});

test('lanes are listed by number', function () {
    Lane::factory()->create(['number' => 7]);
    Lane::factory()->create(['number' => 2]);

    $lanes = app(LaneBoard::class)->lanes();

    expect(array_column($lanes, 'number'))->toBe([2, 7]);
});

test('a lane held for a called party shows who it is for and when the hold ends', function () {
    Lane::factory()->create();
    joinWaitlist(partySize: 3, name: 'Farah');
    app(WaitlistService::class)->callNextParties();

    $lane = app(LaneBoard::class)->lanes()[0];

    expect($lane['state'])->toBe('held')
        ->and($lane['current'])->toMatchArray([
            'customerName' => 'Farah',
            'partySize' => 3,
            'endsAt' => '2026-10-01T19:00:00+00:00',
            'heldUntil' => '2026-10-01T18:05:00+00:00',
        ]);
});

test('a lane with a seated party is in play', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $lane = app(LaneBoard::class)->lanes()[0];

    expect($lane['state'])->toBe('in_play')
        ->and($lane['current']['heldUntil'])->toBeNull();
});

test('a lane is reserved while its reservation has started but not checked in', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());

    $reserved = app(LaneBoard::class)->lanes()[0];
    app(BookingService::class)->checkIn($booking);
    $checkedIn = app(LaneBoard::class)->lanes()[0];

    expect($reserved['state'])->toBe('reserved')
        ->and($reserved['current']['bookingId'])->toBe($booking->id)
        ->and($checkedIn['state'])->toBe('in_play');
});

test('a lane blocked without a booking shows its note', function () {
    $lane = Lane::factory()->create();
    LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'status' => AllocationStatus::Active,
        'note' => 'League night',
    ]);

    $card = app(LaneBoard::class)->lanes()[0];

    expect($card['state'])->toBe('blocked')
        ->and($card['current'])->toMatchArray([
            'bookingId' => null,
            'customerName' => null,
            'note' => 'League night',
        ]);
});

test('an out-of-order lane still shows the session that is on it', function () {
    $lane = Lane::factory()->create();
    bookReservation(now());
    $lane->update(['status' => 'out_of_order']);

    $card = app(LaneBoard::class)->lanes()[0];

    expect($card['state'])->toBe('out_of_order')
        ->and($card['isOpen'])->toBeFalse()
        ->and($card['current'])->not->toBeNull();
});

test('the next allocation is shown alongside the current one', function () {
    Lane::factory()->create();
    bookReservation(now());
    app(BookingService::class)->book(
        Customer::factory()->create(['name' => 'Next Party']),
        60,
        4,
        now()->addHours(2),
        BookingSource::Online,
    );

    $lane = app(LaneBoard::class)->lanes()[0];

    expect($lane['current']['endsAt'])->toBe('2026-10-01T19:00:00+00:00')
        ->and($lane['next'])->toBe([
            'startsAt' => '2026-10-01T20:00:00+00:00',
            'customerName' => 'Next Party',
            'note' => null,
            'isClosure' => false,
            'closureReason' => null,
        ]);
});

test('a session in play can be extended as far as nothing else is booked', function () {
    Lane::factory()->create();
    $entry = joinWaitlist(minutes: 60);
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $current = app(LaneBoard::class)->lanes()[0]['current'];

    expect($current['isRunning'])->toBeTrue()
        ->and($current['extendableMinutes'])->toBeNull();
});

test('a party on several lanes can only be extended until the first of them is booked again', function () {
    $one = Lane::factory()->create(['number' => 1]);
    $two = Lane::factory()->create(['number' => 2]);
    $booking = reserveLanes([$one, $two], now(), minutes: 60);
    app(BookingService::class)->checkIn($booking);
    // Lane 2 closes at 19:30 for this one, half an hour after the party ends.
    reserveLanes($two, now()->addMinutes(150));
    // Lane 1 is not booked again until 21:00.
    reserveLanes($one, now()->addHours(4));

    $cards = app(LaneBoard::class)->lanes();

    expect($cards[0]['current'])->toMatchArray(['isRunning' => true, 'extendableMinutes' => 30, 'partyLaneNumbers' => [1, 2]])
        ->and($cards[1]['current'])->toMatchArray(['isRunning' => true, 'extendableMinutes' => 30, 'partyLaneNumbers' => [1, 2]]);
});

test('a lane that is held, reserved or closed offers no extension', function () {
    $held = Lane::factory()->create(['number' => 1]);
    joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    $reserved = Lane::factory()->create(['number' => 2]);
    reserveLanes($reserved, now());
    $closed = Lane::factory()->create(['number' => 3]);
    reserveLanes($closed, now()->addMinutes(30));

    $cards = app(LaneBoard::class)->lanes();

    expect(array_column($cards, 'state'))->toBe(['held', 'reserved', 'closed_for_reservation'])
        ->and(array_map(fn (array $card) => $card['current']['isRunning'], $cards))->toBe([false, false, false])
        ->and($held->refresh()->isOpen())->toBeTrue();
});

test('a lane is closed in the hour before a reservation and shows who it is for', function () {
    $lane = Lane::factory()->create();
    $booking = reserveLanes($lane, now()->addMinutes(30), partySize: 14, name: 'Farah');

    $card = app(LaneBoard::class)->lanes()[0];

    expect($card['state'])->toBe('closed_for_reservation')
        ->and($card['current'])->toMatchArray([
            'bookingId' => null,
            'customerName' => 'Farah',
            'partySize' => 14,
            'endsAt' => '2026-10-01T18:30:00+00:00',
        ])
        ->and($card['next'])->toBe([
            'startsAt' => '2026-10-01T18:30:00+00:00',
            'customerName' => 'Farah',
            'note' => null,
            'isClosure' => false,
            'closureReason' => null,
        ])
        ->and($booking->allocations)->toHaveCount(1);
});

test('a free lane shows when it will close for a reservation', function () {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addHours(3), name: 'Farah');

    $card = app(LaneBoard::class)->lanes()[0];

    expect($card['state'])->toBe('free')
        ->and($card['next'])->toBe([
            'startsAt' => '2026-10-01T20:00:00+00:00',
            'customerName' => 'Farah',
            'note' => null,
            'isClosure' => true,
            'closureReason' => null,
        ]);
});

test('finished, released and far-off allocations are not shown on a lane', function () {
    $lane = Lane::factory()->create();
    bookReservation(now()->subHours(2));
    bookReservation(now()->addHours(25));
    LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => now(),
        'ends_at' => now()->addHour(),
        'status' => AllocationStatus::Released,
    ]);

    $card = app(LaneBoard::class)->lanes()[0];

    expect($card['state'])->toBe('free')
        ->and($card['current'])->toBeNull()
        ->and($card['next'])->toBeNull();
});

test('the waitlist lists parties in line in order and never exposes their token', function () {
    $first = joinWaitlist(minutes: 120, partySize: 2, name: 'First');
    joinWaitlist(partySize: 5, name: 'Second');
    $left = joinWaitlist(name: 'Gone home');
    app(WaitlistService::class)->leave($left);

    $waitlist = app(LaneBoard::class)->waitlist();

    expect($waitlist)->toHaveCount(2)
        ->and($waitlist[0])->toBe([
            'id' => $first->id,
            'position' => 1,
            'customerName' => 'First',
            'partySize' => 2,
            'minutes' => 120,
            'status' => 'waiting',
            'joinedAt' => '2026-10-01T18:00:00+00:00',
            'calledAt' => null,
            'checkInBy' => null,
            'laneNumbers' => [],
        ])
        ->and($waitlist[1]['customerName'])->toBe('Second')
        ->and($waitlist[1]['position'])->toBe(2);
});

test('a called party shows its lanes and its check-in deadline', function () {
    Lane::factory()->create(['number' => 4]);
    Lane::factory()->create(['number' => 9]);
    joinWaitlist(partySize: 8);
    app(WaitlistService::class)->callNextParties();

    $row = app(LaneBoard::class)->waitlist()[0];

    expect($row)->toMatchArray([
        'status' => 'called',
        'calledAt' => '2026-10-01T18:00:00+00:00',
        'checkInBy' => '2026-10-01T18:05:00+00:00',
        'laneNumbers' => [4, 9],
    ]);
});

test('reservations are listed earliest first and leave out walk-ins', function () {
    Lane::factory()->count(2)->create();
    $later = bookReservation(now()->addHours(3));
    $sooner = bookReservation(now()->addHour(), partySize: 5);
    joinWaitlist();
    app(WaitlistService::class)->callNextParties();

    $reservations = app(LaneBoard::class)->reservations();

    expect(array_column($reservations, 'id'))->toBe([$sooner->id, $later->id])
        ->and($reservations[0])->toMatchArray([
            'customerName' => $sooner->customer->name,
            'phone' => $sooner->customer->phone,
            'partySize' => 5,
            'minutes' => 60,
            'status' => 'confirmed',
            'startsAt' => '2026-10-01T19:00:00+00:00',
            'endsAt' => '2026-10-01T20:00:00+00:00',
        ])
        ->and($reservations[0]['laneNumbers'])->toHaveCount(1);
});

test('reservations that are over, cancelled or more than a day away are left out', function () {
    Lane::factory()->create();
    bookReservation(now()->subHours(2));
    bookReservation(now()->addHours(25));
    bookReservation(now()->addHour())->update(['status' => 'cancelled']);

    $reservations = app(LaneBoard::class)->reservations();

    expect($reservations)->toBeEmpty();
});

test('the session rules come from the config', function () {
    config([
        'bowling.session_step_minutes' => 15,
        'bowling.max_session_minutes' => 180,
        'bowling.max_players_per_lane' => 8,
    ]);

    $rules = app(LaneBoard::class)->sessionRules();

    expect($rules)->toBe([
        'stepMinutes' => 15,
        'maxMinutes' => 180,
        'maxPlayersPerLane' => 8,
    ]);
});
