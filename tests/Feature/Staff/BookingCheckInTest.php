<?php

use App\Enums\BookingStatus;
use App\Models\Lane;
use App\Models\User;

test('staff can check in a confirmed reservation', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());

    $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.bookings.check-in', $booking));

    $response->assertRedirect(route('staff.board'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "{$booking->customer->name} is checked in."]);
    expect($booking->refresh()->status)->toBe(BookingStatus::CheckedIn);
});

test('staff are told when a reservation can no longer be checked in', function () {
    Lane::factory()->create();
    $booking = bookReservation(now());
    $booking->update(['status' => BookingStatus::NoShow]);

    $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.bookings.check-in', $booking));

    $response->assertRedirect(route('staff.board'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a confirmed reservation can be checked in.']);
    expect($booking->refresh()->status)->toBe(BookingStatus::NoShow);
});
