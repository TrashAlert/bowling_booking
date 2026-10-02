<?php

use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Services\BookingService;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('a waiting party sees its place in line and nothing about anyone else', function () {
    $ahead = joinWaitlist(name: 'Party ahead');
    $this->travel(1)->minutes();
    $entry = joinWaitlistOnline(minutes: 90, partySize: 3, name: 'Farah');

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/show')
        ->where('ticket', [
            'status' => 'waiting',
            'customerName' => 'Farah',
            'partySize' => 3,
            'minutes' => 90,
            'joinedAt' => '2026-10-01T18:01:00+00:00',
            'position' => 2,
            'partiesAhead' => 1,
            'estimatedWaitMinutes' => null,
            'laneNumbers' => [],
            'checkInBy' => null,
            'sessionEndsAt' => null,
            'deposit' => ['amountCents' => 1000, 'outcome' => 'held'],
        ])
        ->where('checkInMinutes', 5)
        ->where('serverNow', '2026-10-01T18:01:00+00:00'));
    expect(json_encode($response->inertiaProps()))
        ->not->toContain('Party ahead')
        ->not->toContain($entry->token)
        ->not->toContain($ahead->token);
});

test('a waiting party sees how long it is likely to wait', function () {
    $lane = Lane::factory()->create();
    app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
    joinWaitlist(minutes: 30, name: 'Party ahead');
    $entry = joinWaitlistOnline();

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.status', 'waiting')
        ->where('ticket.estimatedWaitMinutes', 90));
});

test('a called party sees its lane and when it must check in by', function () {
    Lane::factory()->create(['number' => 4]);
    $entry = joinWaitlistOnline();
    app(WaitlistService::class)->callNextParties();

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.status', 'called')
        ->where('ticket.estimatedWaitMinutes', null)
        ->where('ticket.position', 1)
        ->where('ticket.laneNumbers', [4])
        ->where('ticket.checkInBy', '2026-10-01T18:05:00+00:00')
        ->where('ticket.deposit.outcome', 'held'));
});

test('a seated party sees its lane, when its session ends and that its deposit comes off the bill', function () {
    Lane::factory()->create(['number' => 4]);
    $entry = joinWaitlistOnline(minutes: 90);
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.status', 'seated')
        ->where('ticket.position', null)
        ->where('ticket.laneNumbers', [4])
        ->where('ticket.checkInBy', null)
        ->where('ticket.sessionEndsAt', '2026-10-01T19:30:00+00:00')
        ->where('ticket.deposit.outcome', 'applied'));
});

test('a party that missed its call is told its deposit is kept', function () {
    Lane::factory()->create();
    $entry = joinWaitlistOnline();
    app(WaitlistService::class)->callNextParties();
    $this->travel(6)->minutes();
    app(WaitlistService::class)->skipExpiredCalls();

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.status', 'skipped')
        ->where('ticket.position', null)
        ->where('ticket.laneNumbers', [])
        ->where('ticket.deposit.outcome', 'forfeited'));
});

test('a party can leave the line and is told its deposit will be returned', function () {
    $entry = joinWaitlistOnline();

    $response = $this->delete(route('waitlist.destroy', ['entry' => $entry->token]));

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Left);
    $this->get(route('waitlist.show', ['entry' => $entry->token]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'left')
            ->where('ticket.deposit.outcome', 'refund_due'));
});

test('leaving changes nothing for a party that is already playing', function () {
    Lane::factory()->create();
    $entry = joinWaitlistOnline();
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    $response = $this->delete(route('waitlist.destroy', ['entry' => $entry->token]));

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
});

test('a party that joined online while a lane was free has no deposit on its page', function () {
    Lane::factory()->create();
    $entry = app(WaitlistDeposits::class)->start('Farah', '0123456789', 60, 4)->entry;

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.customerName', 'Farah')
        ->where('ticket.deposit', null));
});

test('a walk-in added by staff has no deposit on its page', function () {
    $entry = joinWaitlist(name: 'Walk-in');

    $response = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('ticket.customerName', 'Walk-in')
        ->where('ticket.deposit', null));
});

test('a link that matches no party returns 404', function (string $method, string $token) {
    $entry = joinWaitlistOnline();

    $response = $this->{$method}("/waitlist/{$token}");

    $response->assertNotFound();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);
})->with([
    'opening an unknown link' => ['get', str_repeat('x', 40)],
    'leaving with an unknown link' => ['delete', str_repeat('x', 40)],
    'a link of the wrong length' => ['get', 'too-short'],
]);
