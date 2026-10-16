<?php

use App\Models\LaneRepair;
use App\Models\User;
use App\Services\DashboardSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('a user without a role is told nothing about the venue', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('rightNow', null)
        ->where('attention', null)
        ->where('analytics', null));
});

test('staff see what needs attention and the last seven days of analytics', function () {
    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('rightNow.lanes')
        ->where('attention', [
            'reservationsToMove' => [],
            'lateReservations' => [],
            'requests' => ['waiting' => 0, 'callNow' => 0],
            'closedLanes' => [],
            'onlineJoiningClosed' => false,
        ])
        ->where('analytics.days', 7)
        ->has('analytics.byDay', 7)
        ->where('periods', [1, 7, 30]));
});

test('the analytics cover the days asked for, or seven when that is not on offer', function (string $asked, int $days) {
    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard', ['days' => $asked]));

    $response->assertInertia(fn (Assert $page) => $page->where('analytics.days', $days));
})->with([
    'today' => ['1', 1],
    'thirty days' => ['30', 30],
    'a length not on offer' => ['5', 7],
    'not a number' => ['all', 7],
]);

test('the analytics cover the first to the last day chosen', function (string $from, string $to, int $days) {
    $this->travelTo(venueTime('2026-10-15 14:00'));

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard', ['from' => $from, 'to' => $to]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('analytics.days', $days)
        ->where('analytics.firstDay', $from)
        ->where('analytics.lastDay', $to)
        ->where('customRange', ['from' => $from, 'to' => $to]));
})->with([
    'a month' => ['2026-09-01', '2026-09-30', 30],
    'a whole year, the most allowed' => ['2025-10-15', '2026-10-15', 366],
]);

test('a span of days that cannot be used is turned away with the reason', function (array $span, string $field, string $message) {
    $this->travelTo(venueTime('2026-10-15 14:00'));

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard', $span));

    $response->assertSessionHasErrors([$field => $message]);
})->with([
    'a last day after today' => [['from' => '2026-10-10', 'to' => '2026-10-16'], 'to', 'The last day can\'t be after today.'],
    'a first day after the last' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'from', 'The first day must be on or before the last day.'],
    'more than a year' => [['from' => '2025-10-14', 'to' => '2026-10-15'], 'from', 'Pick at most 366 days.'],
    'only a first day' => [['from' => '2026-10-01'], 'to', 'Pick the last day as well.'],
]);

test('parts an admin has turned off are neither shown nor worked out', function () {
    app(DashboardSettings::class)->save(['rightNow' => false, 'attention' => false]);

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('rightNow', null)
        ->where('attention', null)
        ->where('parts.rightNow', false)
        ->where('parts.totals', true)
        ->has('analytics.byDay'));
});

test('the analytics are not worked out while every part of them is off', function () {
    app(DashboardSettings::class)->save(array_fill_keys(DashboardSettings::ANALYTICS_PARTS, false));

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('analytics', null)
        ->has('attention.requests'));
});

test('time out of order is left out while its part is off', function () {
    LaneRepair::factory()->create();
    app(DashboardSettings::class)->save(['closedTime' => false]);

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('analytics.closedTime', []));
});

test('the app version is shared with every page', function () {
    config(['bowling.version' => '9.8.7']);

    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('version', '9.8.7'));
});
