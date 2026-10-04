<?php

use Inertia\Testing\AssertableInertia as Assert;

test('anyone can open the reservation page without logging in', function () {
    $response = $this->get(route('reserve'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('reserve/lane')
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
        ->where('limits', [
            'maxPartySize' => 200,
            'maxDaysAhead' => 90,
            'checkInOpensMinutes' => 60,
            'noShowGraceMinutes' => 15,
        ])
        ->where('openingHours', null));
});

test('the reservation page gets the opening hours once they are set', function () {
    openDaily('10:00', '23:00', except: [0 => null]);

    $response = $this->get(route('reserve'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('openingHours', 7)
        ->where('openingHours.0', ['weekday' => 1, 'opens' => '10:00', 'closes' => '23:00'])
        ->where('openingHours.6', ['weekday' => 0, 'opens' => null, 'closes' => null]));
});
