<?php

use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\WaitlistEntry;
use App\Services\DemoVenue;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-07 11:00:00');
});

test('a called group arrives two minutes after its call, unless it is one that never turns up', function () {
    Lane::factory()->create();
    $entry = joinWaitlist(name: 'Farah');
    app(WaitlistService::class)->callNextParties();
    $this->travel(2)->minutes();

    app(DemoVenue::class)->liven();

    // One group in eight, picked by its number, misses its call instead.
    expect($entry->refresh()->status)->toBe($entry->id % 8 === 0 ? WaitlistStatus::Called : WaitlistStatus::Seated);
});

test('a group called less than two minutes ago is left for a visitor to seat', function () {
    Lane::factory()->create();
    $entry = joinWaitlist(name: 'Farah');
    app(WaitlistService::class)->callNextParties();
    $this->travel(1)->minutes();

    app(DemoVenue::class)->liven();

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Called);
});

test('a reservation due three minutes ago checks in, unless it is one that never comes', function () {
    $booking = reserveLanes(Lane::factory()->create(), now()->subMinutes(3));

    app(DemoVenue::class)->liven();

    expect($booking->refresh()->status)->toBe($booking->id % 7 === 0 ? BookingStatus::Confirmed : BookingStatus::CheckedIn);
});

test('another group joins while fewer than three are waiting', function () {
    laneInPlay();
    joinWaitlist();
    joinWaitlist();

    app(DemoVenue::class)->liven();

    expect(WaitlistEntry::query()->where('status', WaitlistStatus::Waiting->value)->count())->toBe(3);
});

test('no group joins while three are already waiting', function () {
    laneInPlay();
    joinWaitlist();
    joinWaitlist();
    joinWaitlist();

    app(DemoVenue::class)->liven();

    expect(WaitlistEntry::query()->count())->toBe(3);
});

test('nothing happens while the venue is closed', function () {
    openDaily('09:00', '03:00');
    $this->travelTo(venueTime('2026-10-07 05:00'));

    $done = app(DemoVenue::class)->liven();

    expect($done)->toBe([])
        ->and(WaitlistEntry::query()->count())->toBe(0);
});
