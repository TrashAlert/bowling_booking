<?php

use App\Enums\WaitlistStatus;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('a party sees what it is about to pay', function () {
    laneInPlay();
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    $response = $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/deposit')
        ->where('deposit', [
            'name' => 'Farah',
            'partySize' => 5,
            'minutes' => 90,
            'amountCents' => 1000,
        ]));
});

test('paying puts the party in line and sends it to its own page', function () {
    laneInPlay();
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $entry = WaitlistEntry::query()->sole();
    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($deposit->refresh()->isPaid())->toBeTrue();
});

test('a deposit cannot be paid once joining online has been closed', function () {
    laneInPlay();
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);
    app(WaitlistSettings::class)->setOnlineJoining(false, 'Closed tonight for a private event.');

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $response->assertSessionHasErrors(['closed' => 'Closed tonight for a private event.']);
    expect($deposit->refresh()->isPaid())->toBeFalse();
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('a deposit cannot be paid once the venue has closed', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 22:55'));
    laneInPlay();
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);
    $this->travelTo(venueTime('2026-10-05 23:05'));

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $response->assertSessionHasErrors(['closed' => 'We are closed right now. We open again tomorrow at 10:00 AM.']);
    expect($deposit->refresh()->isPaid())->toBeFalse();
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('a deposit that is already paid leads straight to the place in line', function () {
    $entry = joinWaitlistOnline();
    $deposit = WaitlistDeposit::query()->sole();

    $response = $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
});

test('an unpaid deposit page expires a day after it was started and can no longer be paid', function (string $method) {
    $deposit = WaitlistDeposit::factory()->create(['name' => 'Farah']);
    $this->travel(25)->hours();

    $response = $this->call($method, route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertGone();
    expect($response->getContent())->not->toContain('Farah')
        ->and($deposit->refresh()->isPaid())->toBeFalse();
})->with(['view' => ['GET'], 'pay' => ['POST']]);

test('a deposit link that matches nothing returns 404', function (string $method) {
    $response = $this->{$method}(route('waitlist.deposit.show', ['deposit' => str_repeat('x', 40)]));

    $response->assertNotFound();
    $this->assertDatabaseCount('waitlist_entries', 0);
})->with([
    'opening the page' => 'get',
    'paying' => 'post',
]);
