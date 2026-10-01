<?php

use App\Http\Controllers\Staff\BoardController;
use App\Http\Controllers\Staff\BookingCheckInController;
use App\Http\Controllers\Staff\LaneController;
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

    Route::patch('lanes/{lane}', [LaneController::class, 'update'])->name('lanes.update');
});
