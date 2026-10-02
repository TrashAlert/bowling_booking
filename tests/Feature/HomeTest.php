<?php

use App\Enums\LaneStatus;
use App\Models\Lane;
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
        ->where('lanes', ['free' => 1, 'total' => 4]));
});

test('a lane that has been removed is not counted', function () {
    Lane::factory()->count(3)->create()->first()->delete();

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('lanes', ['free' => 2, 'total' => 2]));
});
