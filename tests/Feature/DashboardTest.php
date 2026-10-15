<?php

use App\Models\User;
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
        ->where('attention', null)
        ->where('analytics', null));
});

test('staff see what needs attention and the last seven days of analytics', function () {
    $response = $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('attention', [
            'reservationsToMove' => [],
            'lateReservations' => [],
            'requests' => ['waiting' => 0, 'callNow' => 0],
            'closedLanes' => [],
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

test('the app version is shared with every page', function () {
    config(['bowling.version' => '9.8.7']);

    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('version', '9.8.7'));
});
