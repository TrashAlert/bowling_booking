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
            'lanes' => [
                ['id' => $two->id, 'number' => 2],
                ['id' => $four->id, 'number' => 4],
            ],
            'notes' => null,
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
