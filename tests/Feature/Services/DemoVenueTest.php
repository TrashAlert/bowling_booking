<?php

use App\Enums\UserRole;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\LaneBlock;
use App\Models\User;
use App\Services\Branding;
use App\Services\DemoVenue;
use App\Services\StaffDashboard;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // A Wednesday evening at the venue.
    $this->travelTo(venueTime('2026-10-07 19:10'));
    app(DemoVenue::class)->seed();
});

test('the demo venue is set up with its name, lanes, hours and two logins', function () {
    $owner = User::query()->where('email', DemoVenue::ADMIN_EMAIL)->sole();
    $counter = User::query()->where('email', DemoVenue::COUNTER_EMAIL)->sole();

    expect(app(Branding::class)->name())->toBe('Strike Zone Bowling')
        ->and(Lane::query()->count())->toBe(12)
        ->and(config('bowling.session_mode'))->toBe('both')
        ->and([$owner->role, $counter->role])->toBe([UserRole::Admin, UserRole::Staff])
        ->and(Hash::check(DemoVenue::ADMIN_PASSWORD, $owner->password))->toBeTrue()
        ->and(Hash::check(DemoVenue::COUNTER_PASSWORD, $counter->password))->toBeTrue();
});

test('the demo shows a busy evening going on', function () {
    $rightNow = app(StaffDashboard::class)->rightNow();

    expect($rightNow['lanes'])->toBe([
        'total' => 12,
        'free' => 0,
        'inPlay' => 7,
        // Two called groups on their way, and a reservation not checked in.
        'heldForParty' => 3,
        'outOfOrder' => 1,
        'maintenance' => 1,
        'keptClear' => 0,
    ])
        ->and($rightNow['line'])->toBe(['waiting' => 4, 'called' => 2]);
});

test('the demo has things for staff to deal with', function () {
    $attention = app(StaffDashboard::class)->attention();

    expect(collect($attention['lateReservations'])->pluck('customerName')->all())->toBe(['Jason Tan'])
        ->and(collect($attention['reservationsToMove'])->pluck('customerName')->all())->toBe(['Kavitha'])
        ->and($attention['requests'])->toBe(['waiting' => 3, 'callNow' => 1])
        ->and(BookingRequest::query()->whereNot('status', 'pending')->count())->toBe(2)
        ->and(LaneBlock::query()->pluck('note')->unique()->all())->toBe(['Wednesday league']);
});

test('the demo has four weeks of history for the dashboard', function () {
    $analytics = app(StaffDashboard::class)->analytics(30);

    expect($analytics['sessions']['walkIns'])->toBeGreaterThan(1000)
        ->and($analytics['sessions']['reservations'])->toBeGreaterThan(300)
        ->and($analytics['noShows'])->toBeGreaterThan(0)
        ->and($analytics['waitlist']['joinedOnline'])->toBeGreaterThan(0)
        ->and($analytics['waitlist']['missedCalls'])->toBeGreaterThan(0)
        ->and(collect($analytics['closedTime'])->firstWhere('number', 5)['repairMinutes'])->toBe(2700);
});
