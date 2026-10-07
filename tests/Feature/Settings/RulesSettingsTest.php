<?php

use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Models\User;
use App\Services\BookingCleanup;
use App\Services\BookingService;
use App\Services\WaitlistService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every rule on the page, as it is before anyone changes it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingRules(array $overrides = []): array
{
    return [
        'waitlist_checkin_minutes' => 5,
        'no_show_grace_minutes' => 15,
        'reservation_lead_minutes' => 60,
        'check_in_opens_minutes' => 60,
        'reservation_max_days_ahead' => 90,
        'max_players_per_lane' => 6,
        'max_session_minutes' => 240,
        ...$overrides,
    ];
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('rules.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the booking rules', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())
        ->{$method}(route($route), bookingRules(['waitlist_checkin_minutes' => 30]));

    $response->assertForbidden();
    expect(config('bowling.waitlist_checkin_minutes'))->toBe(5);
    $this->assertDatabaseCount('settings', 0);
})->with([
    'open the page' => ['get', 'rules.edit'],
    'change the rules' => ['put', 'rules.update'],
]);

test('admins see each rule as it is now, and how far it can be changed', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('rules.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/rules')
        ->where('rules', bookingRules())
        ->where('limits.waitlist_checkin_minutes', ['min' => 2, 'max' => 60])
        ->where('limits.no_show_grace_minutes', ['min' => 5, 'max' => 120])
        ->where('limits.reservation_lead_minutes', ['min' => 0, 'max' => 240])
        ->where('limits.check_in_opens_minutes', ['min' => 5, 'max' => 240])
        ->where('limits.reservation_max_days_ahead', ['min' => 1, 'max' => 365])
        ->where('limits.max_players_per_lane', ['min' => 2, 'max' => 20])
        ->where('limits.max_session_minutes', ['min' => 30, 'max' => 720, 'step' => 30]));
});

test('admins can change how long a called party has to check in', function () {
    $this->travelTo('2026-10-01 18:00:00');

    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['waitlist_checkin_minutes' => 8]));

    $response->assertRedirect(route('rules.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking rules saved.']);
    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page->where('checkInMinutes', 8));

    // The next party called gets the new time, and is skipped once it is up.
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    expect($entry->refresh()->booking->allocations()->sole()->held_until->toDateTimeString())->toBe('2026-10-01 18:08:00');

    $this->travel(7)->minutes();
    app(WaitlistService::class)->skipExpiredCalls();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Called);

    $this->travel(2)->minutes();
    app(WaitlistService::class)->skipExpiredCalls();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Skipped);
});

test('admins can change how late a reservation can arrive before it is a no-show', function () {
    $this->travelTo('2026-10-01 18:00:00');
    $booking = reserveLanes(Lane::factory()->create(), now()->addHour());

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['no_show_grace_minutes' => 30]))
        ->assertSessionHasNoErrors();

    // Twenty minutes late: a no-show under the old 15 minutes, not under 30.
    $this->travelTo('2026-10-01 19:20:00');
    app(BookingCleanup::class)->markNoShows();
    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);

    $this->travelTo('2026-10-01 19:31:00');
    app(BookingCleanup::class)->markNoShows();
    expect($booking->refresh()->status)->toBe(BookingStatus::NoShow);
});

test('a new closing time applies to reservations made afterwards, and ones already made keep theirs', function () {
    $this->travelTo('2026-10-01 18:00:00');
    [$first, $second] = Lane::factory()->count(2)->create();
    $before = reserveLanes($first, now()->addHours(3));

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['reservation_lead_minutes' => 30]))
        ->assertSessionHasNoErrors();
    $after = reserveLanes($second, now()->addHours(3));

    expect($before->closures()->sole()->starts_at->toDateTimeString())->toBe('2026-10-01 20:00:00')
        ->and($after->closures()->sole()->starts_at->toDateTimeString())->toBe('2026-10-01 20:30:00');
    $this->get(route('staff.reservations.index'))->assertInertia(fn (Assert $page) => $page->where('limits.leadMinutes', 30));
});

test('with no closing time a reserved lane stays in use right up to the start', function () {
    $this->travelTo('2026-10-01 18:00:00');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['reservation_lead_minutes' => 0]))
        ->assertSessionHasNoErrors();
    $booking = reserveLanes(Lane::factory()->create(), now()->addHours(3));

    expect($booking->closures()->count())->toBe(0)
        ->and($booking->allocations()->sole()->starts_at->toDateTimeString())->toBe('2026-10-01 21:00:00');
});

test('admins can change how early a reservation can be checked in', function () {
    $this->travelTo('2026-10-01 18:00:00');
    $booking = reserveLanes(Lane::factory()->create(), now()->addHours(3));

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['check_in_opens_minutes' => 30]))
        ->assertSessionHasNoErrors();

    // Forty-five minutes early: allowed under the old hour, refused under 30 minutes.
    $this->travelTo('2026-10-01 20:15:00');
    expect(fn () => app(BookingService::class)->checkIn($booking))
        ->toThrow(InvalidStateException::class, 'Check-in opens 30 minutes before the start.');

    $this->travelTo('2026-10-01 20:30:00');
    expect(app(BookingService::class)->checkIn($booking)->status)->toBe(BookingStatus::CheckedIn);
});

test('admins can change how far ahead a reservation can be made, for customers and staff alike', function () {
    $this->travelTo('2026-10-01 18:00:00');
    $admin = User::factory()->admin()->create();
    $lane = Lane::factory()->create();
    $request = fn (int $days) => [
        'name' => 'Farah', 'phone' => '0123456789', 'party_size' => 2, 'minutes' => 60,
        'starts_at' => now()->addDays($days)->setTime(11, 0)->toIso8601String(), 'call_any_time' => '1',
    ];

    $this->actingAs($admin)
        ->put(route('rules.update'), bookingRules(['reservation_max_days_ahead' => 7]))
        ->assertSessionHasNoErrors();

    $this->get(route('reserve'))->assertInertia(fn (Assert $page) => $page->where('limits.maxDaysAhead', 7));
    $this->post(route('booking-requests.store'), $request(10))
        ->assertSessionHasErrors(['starts_at' => 'Reservations can be made up to 7 days ahead.']);
    $this->post(route('staff.reservations.store'), [...$request(10), 'lane_ids' => [$lane->id]])
        ->assertSessionHasErrors(['starts_at' => 'Reservations can be made up to 7 days ahead.']);
    $this->post(route('booking-requests.store'), $request(6))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('booking_requests', 1);
    $this->assertDatabaseCount('bookings', 0);
});

test('admins can change how many people share a lane', function () {
    $admin = User::factory()->admin()->create();
    $joining = fn (int $people) => ['name' => 'Farah', 'phone' => '0123456789', 'party_size' => $people, 'minutes' => 60];

    $this->actingAs($admin)
        ->put(route('rules.update'), bookingRules(['max_players_per_lane' => 4]))
        ->assertSessionHasNoErrors();

    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page->where('session.maxPlayersPerLane', 4));
    $this->post(route('waitlist.store'), $joining(5))
        ->assertSessionHasErrors(['party_size' => 'A lane takes up to 4 people. Join again for the rest of your group.']);
    $this->post(route('staff.waitlist.store'), $joining(5))
        ->assertSessionHasErrors(['party_size' => 'A lane takes up to 4 people. Add another walk-in for the rest of the group.']);
    expect(app(BookingService::class)->lanesNeeded(4))->toBe(1)
        ->and(app(BookingService::class)->lanesNeeded(5))->toBe(2);
});

test('admins can change the longest session, and the lengths on offer follow it', function () {
    Lane::factory()->create();
    $joining = fn (int $minutes) => ['name' => 'Farah', 'phone' => '0123456789', 'party_size' => 2, 'minutes' => $minutes];

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules(['max_session_minutes' => 120]))
        ->assertSessionHasNoErrors();

    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page
        ->where('session.maxMinutes', 120)
        ->has('waitMinutes', 4)
        ->has('waitMinutes.120')
        ->missing('waitMinutes.150'));
    $this->post(route('waitlist.store'), $joining(150))
        ->assertSessionHasErrors(['minutes' => 'The minutes field must not be greater than 120.']);
    $this->post(route('waitlist.store'), $joining(120))->assertSessionHasNoErrors();
});

test('a booking rule that is not acceptable is refused', function (string $rule, mixed $value, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('rules.update'), bookingRules([$rule => $value]));

    $response->assertSessionHasErrors([$rule => $message]);
    $this->assertDatabaseCount('settings', 0);
})->with([
    'no minutes to check in' => ['waitlist_checkin_minutes', '', 'The minutes to check in field is required.'],
    'minutes to check in that are not a whole number' => ['waitlist_checkin_minutes', '4.5', 'The minutes to check in field must be an integer.'],
    'too few minutes to check in' => ['waitlist_checkin_minutes', 1, 'The minutes to check in field must be at least 2.'],
    'too many minutes to check in' => ['waitlist_checkin_minutes', 61, 'The minutes to check in field must not be greater than 60.'],
    'no no-show grace' => ['no_show_grace_minutes', '', 'The no-show grace field is required.'],
    'too short a no-show grace' => ['no_show_grace_minutes', 4, 'The no-show grace field must be at least 5.'],
    'too long a no-show grace' => ['no_show_grace_minutes', 121, 'The no-show grace field must not be greater than 120.'],
    'no lane closing time' => ['reservation_lead_minutes', '', 'The lane closing time field is required.'],
    'a lane closing time below zero' => ['reservation_lead_minutes', -1, 'The lane closing time field must be at least 0.'],
    'too long a lane closing time' => ['reservation_lead_minutes', 241, 'The lane closing time field must not be greater than 240.'],
    'no check-in opening time' => ['check_in_opens_minutes', '', 'The check-in opening time field is required.'],
    'too short a check-in opening time' => ['check_in_opens_minutes', 4, 'The check-in opening time field must be at least 5.'],
    'too long a check-in opening time' => ['check_in_opens_minutes', 241, 'The check-in opening time field must not be greater than 240.'],
    'no days ahead' => ['reservation_max_days_ahead', '', 'The days ahead field is required.'],
    'too few days ahead' => ['reservation_max_days_ahead', 0, 'The days ahead field must be at least 1.'],
    'too many days ahead' => ['reservation_max_days_ahead', 366, 'The days ahead field must not be greater than 365.'],
    'no people per lane' => ['max_players_per_lane', '', 'The people per lane field is required.'],
    'too few people per lane' => ['max_players_per_lane', 1, 'The people per lane field must be at least 2.'],
    'too many people per lane' => ['max_players_per_lane', 21, 'The people per lane field must not be greater than 20.'],
    'no longest session' => ['max_session_minutes', '', 'The longest session field is required.'],
    'a longest session shorter than one step' => ['max_session_minutes', 0, 'The longest session field must be at least 30.'],
    'a longest session that is not a whole number of steps' => ['max_session_minutes', 100, 'The longest session field must be a multiple of 30.'],
    'too long a longest session' => ['max_session_minutes', 750, 'The longest session field must not be greater than 720.'],
]);
