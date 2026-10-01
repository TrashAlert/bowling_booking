<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingStatus;
use App\Models\Lane;
use App\Services\BookingCleanup;
use App\Services\BookingService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('a reservation nobody checked in for becomes a no-show after the grace period', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());
    $this->travel(16)->minutes();

    $marked = app(BookingCleanup::class)->markNoShows();

    expect($marked)->toBe(1)
        ->and($booking->refresh()->status)->toBe(BookingStatus::NoShow)
        ->and($booking->allocations->first()->status)->toBe(AllocationStatus::Released);
});

test('a reservation is not a no-show while the grace period is still running', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());
    $this->travel(14)->minutes();

    $marked = app(BookingCleanup::class)->markNoShows();

    expect($marked)->toBe(0)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('a checked-in reservation is never marked as a no-show', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());
    app(BookingService::class)->checkIn($booking);
    $this->travel(16)->minutes();

    $marked = app(BookingCleanup::class)->markNoShows();

    expect($marked)->toBe(0)
        ->and($booking->refresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->allocations->first()->status)->toBe(AllocationStatus::Active);
});
