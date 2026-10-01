<?php

use App\Http\Controllers\Staff\BoardController;
use App\Http\Controllers\Staff\BookingCheckInController;
use App\Http\Controllers\Staff\BookingExtensionController;
use App\Http\Controllers\Staff\LaneController;
use App\Http\Controllers\Staff\ReservationController;
use App\Http\Controllers\Staff\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'staff'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('board', BoardController::class)->name('board');

    Route::post('waitlist', [WaitlistController::class, 'store'])->name('waitlist.store');
    Route::post('waitlist/call-next', [WaitlistController::class, 'callNext'])->name('waitlist.call-next');
    Route::post('waitlist/{entry}/seat', [WaitlistController::class, 'seat'])->name('waitlist.seat');
    Route::post('waitlist/{entry}/skip', [WaitlistController::class, 'skip'])->name('waitlist.skip');
    Route::delete('waitlist/{entry}', [WaitlistController::class, 'destroy'])->name('waitlist.destroy');

    Route::post('bookings/{booking}/check-in', [BookingCheckInController::class, 'store'])->name('bookings.check-in');
    Route::post('bookings/{booking}/extensions', [BookingExtensionController::class, 'store'])->name('bookings.extend');

    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::patch('reservations/{booking}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::delete('reservations/{booking}', [ReservationController::class, 'destroy'])->name('reservations.destroy');

    Route::patch('lanes/{lane}', [LaneController::class, 'update'])->name('lanes.update');
});
