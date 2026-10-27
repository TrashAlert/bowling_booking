<?php

use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FrontPageCoverController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReserveLaneController;
use App\Http\Controllers\WaitlistDepositController;
use App\Http\Controllers\WaitlistDepositNoticeController;
use App\Http\Controllers\WaitlistEntryController;
use App\Http\Controllers\WaitlistJoinController;
use App\Http\Controllers\WaitlistPushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// The business logo an admin uploaded, shown on every page.
Route::get('branding/logo', BrandingLogoController::class)->name('branding.logo');

// The photo an admin uploaded for the top of the front page.
Route::get('branding/cover', FrontPageCoverController::class)->name('front-page.cover');

// Joining the waitlist online: the form, the deposit, then the party's own
// page. The last two are reached by a secret token in the address.
Route::get('waitlist/join', [WaitlistJoinController::class, 'create'])->name('waitlist.join');
Route::post('waitlist/join', [WaitlistJoinController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('waitlist.store');

// Where a payment provider tells us a deposit has been paid. It is not a
// page: the provider's server posts here, so there is no session to check.
Route::post('waitlist/deposit/notice/{provider}', WaitlistDepositNoticeController::class)
    ->middleware('throttle:60,1')
    ->name('waitlist.deposit.notice');

// The pages reached by a secret link are kept out of search engines.
Route::get('waitlist/deposit/{deposit:token}', [WaitlistDepositController::class, 'show'])
    ->middleware('noindex')
    ->name('waitlist.deposit.show');
Route::post('waitlist/deposit/{deposit:token}', [WaitlistDepositController::class, 'store'])->name('waitlist.deposit.store');

Route::get('waitlist/{entry:token}', [WaitlistEntryController::class, 'show'])
    ->where('entry', '[A-Za-z0-9]{40}')
    ->middleware('noindex')
    ->name('waitlist.show');
Route::delete('waitlist/{entry:token}', [WaitlistEntryController::class, 'destroy'])
    ->where('entry', '[A-Za-z0-9]{40}')
    ->name('waitlist.destroy');
// The group changes its name, size or length, once.
Route::patch('waitlist/{entry:token}', [WaitlistEntryController::class, 'update'])
    ->where('entry', '[A-Za-z0-9]{40}')
    ->middleware('throttle:10,1')
    ->name('waitlist.update');
Route::put('waitlist/{entry:token}/push', [WaitlistPushSubscriptionController::class, 'store'])
    ->where('entry', '[A-Za-z0-9]{40}')
    ->middleware('throttle:10,1')
    ->name('waitlist.push.store');

// Asking for a reservation. Staff call the customer to confirm it.
Route::get('reserve', [ReserveLaneController::class, 'create'])->name('reserve');
Route::post('reserve', [ReserveLaneController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('booking-requests.store');
Route::get('reserve/thanks', [ReserveLaneController::class, 'thanks'])
    ->middleware('noindex')
    ->name('booking-requests.thanks');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/staff.php';
