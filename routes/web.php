<?php

use App\Http\Controllers\ReserveLaneController;
use App\Http\Controllers\WaitlistJoinController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('waitlist/join', WaitlistJoinController::class)->name('waitlist.join');
Route::get('reserve', ReserveLaneController::class)->name('reserve');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/staff.php';
