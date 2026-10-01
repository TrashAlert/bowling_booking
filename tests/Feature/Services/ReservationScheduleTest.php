<?php

use App\Models\Lane;
use App\Services\BookingCleanup;
use App\Services\BookingService;
use App\Services\ReservationSchedule;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00:00');
});

test('a day lists its reservations earliest first with their lanes', function () {
    $four = Lane::factory()->create(['number' => 4]);
    $two = Lane::factory()->create(['number' => 2]);
    $later = reserveLanes($four, now()->addHours(8), name: 'Later');
    $sooner = reserveLanes([$four, $two], now()->addHours(3), minutes: 90, partySize: 14, name: 'Sooner');

    $rows = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay());

    expect(array_column($rows, 'id'))->toBe([$sooner->id, $later->id])
        ->and($rows[0])->toBe([
            'id' => $sooner->id,
            'customerName' => 'Sooner',
            'phone' => '0123456789',
            'partySize' => 14,
            'minutes' => 90,
            'status' => 'confirmed',
            'startsAt' => '2026-10-01T13:00:00+00:00',
            'endsAt' => '2026-10-01T14:30:00+00:00',
            'checkInOpensAt' => '2026-10-01T12:00:00+00:00',
            'lanes' => [
                ['id' => $two->id, 'number' => 2],
                ['id' => $four->id, 'number' => 4],
            ],
            'notes' => null,
            'closedLaneNumbers' => [],
        ]);
});

test('only reservations that start inside the window are listed', function () {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addHours(13), name: 'Late tonight');
    reserveLanes($lane, now()->addHours(15), name: 'After midnight');

    $rows = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay());

    expect(array_column($rows, 'customerName'))->toBe(['Late tonight']);
});

test('cancelled reservations and no-shows stay on the list with their time and lane', function () {
    $lane = Lane::factory()->create(['number' => 5]);
    $cancelled = reserveLanes($lane, now()->addHours(2), name: 'Cancelled');
    app(BookingService::class)->cancel($cancelled);
    reserveLanes($lane, now()->addHours(4), name: 'No show');
    $this->travel(5)->hours();
    app(BookingCleanup::class)->markNoShows();

    $rows = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay());

    expect(array_column($rows, 'status', 'customerName'))->toBe([
        'Cancelled' => 'cancelled',
        'No show' => 'no_show',
    ])
        ->and($rows[0]['startsAt'])->toBe('2026-10-01T12:00:00+00:00')
        ->and($rows[0]['lanes'])->toBe([['id' => $lane->id, 'number' => 5]]);
});

test('walk-in sessions are not listed as reservations', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $rows = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay());

    expect($rows)->toBeEmpty();
});

test('a search finds reservations on any day by part of the name, whatever the case', function () {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addDays(10), name: 'Nurul Farahin');
    reserveLanes($lane, now()->addHours(3), name: 'Daniel Lee');
    reserveLanes($lane, now()->addDays(3), name: 'Farah Aziz');

    $rows = app(ReservationSchedule::class)->search('FARAH');

    expect(array_column($rows, 'startsAt', 'customerName'))->toBe([
        'Farah Aziz' => '2026-10-04T10:00:00+00:00',
        'Nurul Farahin' => '2026-10-11T10:00:00+00:00',
    ]);
});

test('a search lists what is still to come soonest first, then past reservations latest first', function () {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now(), name: 'Ali on the 1st');
    reserveLanes($lane, now()->addDays(10), name: 'Ali on the 11th');
    reserveLanes($lane, now()->addDay(), name: 'Ali on the 2nd');
    reserveLanes($lane, now()->addDays(6), name: 'Ali on the 7th');
    $this->travel(5)->days();

    $rows = app(ReservationSchedule::class)->search('ali');

    expect(array_column($rows, 'customerName'))->toBe([
        'Ali on the 7th',
        'Ali on the 11th',
        'Ali on the 2nd',
        'Ali on the 1st',
    ]);
});

test('a search finds a phone number however it or the search was typed', function (string $term) {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addHours(3), name: 'Farah')->customer->update(['phone' => '012-345 6789']);
    reserveLanes($lane, now()->addHours(6), name: 'Daniel')->customer->update(['phone' => '019 888 7777']);

    $rows = app(ReservationSchedule::class)->search($term);

    expect(array_column($rows, 'customerName'))->toBe(['Farah']);
})->with([
    'digits only' => '0123456',
    'with spaces' => '012 345 67',
    'with brackets and a dash' => '(012) 345-6789',
]);

test('a search with words in it is not looked for in phone numbers', function () {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addHours(3), name: 'Lane 9 Legends')->customer->update(['phone' => '0123456780']);
    reserveLanes($lane, now()->addHours(6), name: 'Daniel')->customer->update(['phone' => '0199999999']);

    $rows = app(ReservationSchedule::class)->search('9 leg');

    expect(array_column($rows, 'customerName'))->toBe(['Lane 9 Legends']);
});

test('wildcard characters in a search are looked for as typed', function (string $term, string $found) {
    $lane = Lane::factory()->create();
    reserveLanes($lane, now()->addHours(3), name: '100% Strikers');
    reserveLanes($lane, now()->addHours(6), name: 'Pin_Pals');
    reserveLanes($lane, now()->addHours(9), name: 'Farah');

    $rows = app(ReservationSchedule::class)->search($term);

    expect(array_column($rows, 'customerName'))->toBe([$found]);
})->with([
    'percent sign' => ['%', '100% Strikers'],
    'underscore' => ['_', 'Pin_Pals'],
]);

test('walk-in sessions are not found by a search', function () {
    Lane::factory()->create();
    $entry = joinWaitlist(name: 'Farah');
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $rows = app(ReservationSchedule::class)->search('Farah');

    expect($rows)->toBeEmpty();
});
