<?php

use Inertia\Testing\AssertableInertia as Assert;

test('anyone can open the waitlist page without logging in', function () {
    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/join')
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
        ->where('checkInMinutes', 5));
});
