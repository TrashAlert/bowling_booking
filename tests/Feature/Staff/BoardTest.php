<?php

use App\Models\Lane;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('staff see the lanes, waitlist, reservations and session rules on the board', function () {
    $this->travelTo('2026-10-01 18:00:00');
    Lane::factory()->count(2)->create();
    bookReservation(now()->addHour());
    $entry = joinWaitlist(name: 'Farah');

    $response = $this->actingAs(User::factory()->staff()->create())->get(route('staff.board'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('staff/board')
        ->has('lanes', 2)
        ->has('reservations', 1)
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
        ->has('waitlist', 1, fn (Assert $row) => $row
            ->where('id', $entry->id)
            ->where('customerName', 'Farah')
            ->missing('token')
            ->etc())
        ->where('serverNow', '2026-10-01T18:00:00+00:00'));
});
