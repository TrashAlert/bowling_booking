<?php

use App\Enums\LaneStatus;
use App\Models\Lane;
use App\Services\WaitlistService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('the front page tells anyone how many lanes are free right now', function () {
    [$inPlay, $closedAhead, $outOfOrder] = Lane::factory()->count(4)->create();
    reserveLanes($inPlay, now());
    reserveLanes($closedAhead, now()->addMinutes(30));
    $outOfOrder->update(['status' => LaneStatus::OutOfOrder]);

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('lanes', ['free' => 1, 'total' => 4, 'waiting' => 0]));
});

test('the front page says whether the venue is open and when that changes', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 23:30'));

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('opening', ['isOpen' => false, 'opensAt' => '2026-10-06T02:00:00+00:00', 'closesAt' => null]));
});

test('a lane that has been removed is not counted', function () {
    Lane::factory()->count(3)->create()->first()->delete();

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('lanes', ['free' => 2, 'total' => 2, 'waiting' => 0]));
});

test('the lanes that parties in the waitlist are about to get are not counted as free', function () {
    Lane::factory()->count(3)->create();
    joinWaitlist(name: 'Called to a lane');
    joinWaitlist(name: 'Called to another');
    app(WaitlistService::class)->callNextParties();
    joinWaitlist(name: 'Still waiting, next for the last lane');

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('lanes', ['free' => 0, 'total' => 3, 'waiting' => 3]));
});

test('a waiting party that fits no lane right now does not take one off the count', function () {
    // The lane closes at 18:30 for a reservation, so an hour's session can't start on it now.
    [$closingSoon] = Lane::factory()->count(2)->create();
    reserveLanes($closingSoon, now()->addMinutes(90));
    joinWaitlist(minutes: 60, name: 'Takes the other lane');
    joinWaitlist(minutes: 60, name: 'Has to wait');

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('lanes', ['free' => 1, 'total' => 2, 'waiting' => 2]));
});
