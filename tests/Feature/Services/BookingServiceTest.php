<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Customer;
use App\Models\Lane;
use App\Services\BookingService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('a booking takes one lane per six players and is confirmed', function () {
    Lane::factory()->count(3)->create();

    $booking = app(BookingService::class)->book(
        Customer::factory()->create(),
        90,
        8,
        now()->addHour(),
        BookingSource::Phone,
    );

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Phone)
        ->and($booking->minutes)->toBe(90)
        ->and($booking->total_cents)->toBe(0)
        ->and($booking->allocations)->toHaveCount(2);

    expect($booking->allocations->first())
        ->status->toBe(AllocationStatus::Active)
        ->held_until->toBeNull()
        ->and($booking->allocations->first()->starts_at->toDateTimeString())->toBe('2026-10-01 19:00:00')
        ->and($booking->allocations->first()->ends_at->toDateTimeString())->toBe('2026-10-01 20:30:00');
});

test('the number of players per lane comes from the config', function () {
    config(['bowling.max_players_per_lane' => 4]);
    Lane::factory()->count(3)->create();

    $booking = bookReservation(now()->addHour(), partySize: 9);

    expect($booking->allocations)->toHaveCount(3);
});

test('a held booking is pending and its lanes are held for ten minutes', function () {
    Lane::factory()->create();

    $booking = app(BookingService::class)->book(
        Customer::factory()->create(),
        60,
        4,
        now()->addHour(),
        BookingSource::Online,
        hold: true,
    );

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->allocations->first()->status)->toBe(AllocationStatus::Held)
        ->and($booking->allocations->first()->held_until->toDateTimeString())->toBe('2026-10-01 18:10:00');
});

test('nothing is saved when there are not enough lanes', function () {
    Lane::factory()->create();
    $customer = Customer::factory()->create();

    expect(fn () => app(BookingService::class)->book($customer, 60, 8, now()->addHour(), BookingSource::Phone))
        ->toThrow(function (NoLaneAvailableException $exception) {
            expect($exception->needed)->toBe(2)
                ->and($exception->found)->toBe(1);
        });

    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('lane_allocations', 0);
});

test('a lane that is out of order is not booked', function () {
    Lane::factory()->outOfOrder()->create();
    $customer = Customer::factory()->create();

    expect(fn () => app(BookingService::class)->book($customer, 60, 4, now()->addHour(), BookingSource::Phone))
        ->toThrow(NoLaneAvailableException::class);
});

test('a session can start the moment the previous one ends', function () {
    Lane::factory()->create();
    bookReservation(now()->addHour());

    $booking = bookReservation(now()->addHours(2));

    expect($booking->allocations)->toHaveCount(1);
});

test('a session that overlaps a booked one is refused', function () {
    Lane::factory()->create();
    bookReservation(now()->addHour());

    expect(fn () => bookReservation(now()->addMinutes(90)))
        ->toThrow(NoLaneAvailableException::class);

    $this->assertDatabaseCount('bookings', 1);
});

test('checking in a confirmed reservation marks it as checked in', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());

    $checkedIn = app(BookingService::class)->checkIn($booking);

    expect($checkedIn->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->refresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->allocations->first()->status)->toBe(AllocationStatus::Active);
});

test('a booking that is not a confirmed reservation cannot be checked in', function (BookingStatus $status) {
    Lane::factory()->create();
    $booking = bookReservation(now());
    $booking->update(['status' => $status]);

    expect(fn () => app(BookingService::class)->checkIn($booking))
        ->toThrow(InvalidStateException::class, 'Only a confirmed reservation can be checked in.');

    expect($booking->refresh()->status)->toBe($status);
})->with([
    'pending' => BookingStatus::Pending,
    'already checked in' => BookingStatus::CheckedIn,
    'completed' => BookingStatus::Completed,
    'cancelled' => BookingStatus::Cancelled,
    'no-show' => BookingStatus::NoShow,
]);
