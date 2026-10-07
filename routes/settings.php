<?php

use App\Http\Controllers\Settings\DepositController;
use App\Http\Controllers\Settings\LaneController;
use App\Http\Controllers\Settings\OpeningHoursController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('settings/lanes', [LaneController::class, 'edit'])->name('lanes.edit');
    Route::put('settings/lanes', [LaneController::class, 'update'])->name('lanes.update');

    Route::get('settings/opening-hours', [OpeningHoursController::class, 'edit'])->name('opening-hours.edit');
    Route::put('settings/opening-hours', [OpeningHoursController::class, 'update'])->name('opening-hours.update');

    Route::get('settings/deposit', [DepositController::class, 'edit'])->name('deposit.edit');
    Route::put('settings/deposit', [DepositController::class, 'update'])->name('deposit.update');

    Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
    Route::post('settings/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('settings/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
