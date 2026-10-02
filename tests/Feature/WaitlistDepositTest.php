<?php

use App\Enums\WaitlistStatus;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\WaitlistDeposits;
use Inertia\Testing\AssertableInertia as Assert;

test('a party sees what it is about to pay', function () {
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
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $entry = WaitlistEntry::query()->sole();
    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($deposit->refresh()->isPaid())->toBeTrue();
});

test('a deposit that is already paid leads straight to the place in line', function () {
    $entry = joinWaitlistOnline();
    $deposit = WaitlistDeposit::query()->sole();

    $response = $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
});

test('a deposit link that matches nothing returns 404', function (string $method) {
    $response = $this->{$method}(route('waitlist.deposit.show', ['deposit' => str_repeat('x', 40)]));

    $response->assertNotFound();
    $this->assertDatabaseCount('waitlist_entries', 0);
})->with([
    'opening the page' => 'get',
    'paying' => 'post',
]);
