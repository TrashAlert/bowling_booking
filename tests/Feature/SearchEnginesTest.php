<?php

use App\Models\BookingRequest;
use App\Models\WaitlistDeposit;

test('pages reached by a secret link ask search engines not to list them', function (Closure $visit) {
    $response = $visit($this);

    $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with([
    'a party\'s place in line' => [fn ($test) => $test->get(route('waitlist.show', ['entry' => joinWaitlist()->token]))],
    'an unpaid deposit' => [fn ($test) => $test->get(route('waitlist.deposit.show', ['deposit' => WaitlistDeposit::factory()->create()->token]))],
    'the thanks after a reservation request' => [fn ($test) => $test
        ->withSession(['booking_request_id' => BookingRequest::factory()->create()->id])
        ->get(route('booking-requests.thanks'))],
]);

test('the public pages can still be found', function (string $route) {
    $response = $this->get(route($route));

    $response->assertOk()->assertHeaderMissing('X-Robots-Tag');
})->with(['home', 'waitlist.join', 'reserve']);
