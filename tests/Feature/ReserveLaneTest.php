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
        ]));
});
