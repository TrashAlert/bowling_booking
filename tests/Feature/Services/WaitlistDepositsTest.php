<?php

use App\Enums\DepositOutcome;
use App\Enums\LaneStatus;
use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('asking to join online notes the request without putting the party in line', function () {
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    expect($deposit->isPaid())->toBeFalse()
        ->and($deposit->amount_cents)->toBe(1000)
        ->and($deposit->outcome())->toBeNull()
        ->and($deposit->token)->toHaveLength(40);
    $this->assertDatabaseCount('waitlist_entries', 0);
    $this->assertDatabaseCount('customers', 0);
});

test('no deposit is asked for while a lane is free, and the party is in line at once', function () {
    Lane::factory()->create();

    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    expect($deposit->amount_cents)->toBe(0)
        ->and($deposit->entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($deposit->entry->customer->name)->toBe('Farah');
});

test('the deposit applies once the party would have to wait for a lane', function (Closure $busy) {
    $busy(Lane::factory()->create());

    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 60, 5);

    expect($deposit->amount_cents)->toBe(1000)
        ->and($deposit->entry)->toBeNull();
})->with([
    'the lane is in play' => [fn (Lane $lane) => app(BookingService::class)->checkIn(reserveLanes($lane, now()))],
    'the lane is out of order' => [fn (Lane $lane) => $lane->update(['status' => LaneStatus::OutOfOrder])],
    'someone is already waiting for the lane' => [fn () => joinWaitlist()],
    'the session would run into a reservation' => [fn (Lane $lane) => reserveLanes($lane, now()->addMinutes(90))],
]);

test('the deposit amount comes from the config', function () {
    config(['bowling.waitlist_deposit_cents' => 2500]);

    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    expect($deposit->amount_cents)->toBe(2500);
});

test('paying the deposit puts the party in line with what it asked for', function () {
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 90, 5);

    $entry = app(WaitlistDeposits::class)->confirmPayment($deposit);

    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->minutes)->toBe(90)
        ->and($entry->party_size)->toBe(5)
        ->and($entry->customer->name)->toBe('Farah')
        ->and($entry->customer->phone)->toBe('0123456789');
    expect($deposit->refresh()->paid_at->toIso8601String())->toBe('2026-10-01T18:00:00+00:00')
        ->and($deposit->waitlist_entry_id)->toBe($entry->id);
});

test('a party takes its place in line when it pays, not when it asked to join', function () {
    $slowPayer = app(WaitlistDeposits::class)->start('Slow payer', '0123456789', 60, 4);
    $this->travel(2)->minutes();
    joinWaitlist(name: 'Joined at the counter meanwhile');
    $this->travel(2)->minutes();

    $entry = app(WaitlistDeposits::class)->confirmPayment($slowPayer);

    expect($entry->position())->toBe(2);
});

test('a deposit reported as paid twice keeps the party in line once', function () {
    $deposit = app(WaitlistDeposits::class)->start('Farah', '0123456789', 60, 4);
    $first = app(WaitlistDeposits::class)->confirmPayment($deposit);

    $second = app(WaitlistDeposits::class)->confirmPayment($deposit);

    expect($second->id)->toBe($first->id);
    $this->assertDatabaseCount('waitlist_entries', 1);
    $this->assertDatabaseCount('customers', 1);
});

test('what becomes of a paid deposit follows what happened to the party', function (Closure $happens, DepositOutcome $outcome) {
    Lane::factory()->create();
    $entry = joinWaitlistOnline();
    $happens($entry, $this);

    $deposit = WaitlistDeposit::query()->sole();

    expect($deposit->outcome())->toBe($outcome);
})->with([
    'still waiting: held' => [
        fn () => null,
        DepositOutcome::Held,
    ],
    'called and yet to check in: held' => [
        fn () => app(WaitlistService::class)->callNextParties(),
        DepositOutcome::Held,
    ],
    'seated: comes off the bill' => [
        function (WaitlistEntry $entry) {
            app(WaitlistService::class)->callNextParties();
            app(WaitlistService::class)->seat($entry);
        },
        DepositOutcome::Applied,
    ],
    'left before being called: returned' => [
        fn (WaitlistEntry $entry) => app(WaitlistService::class)->leave($entry),
        DepositOutcome::RefundDue,
    ],
    'left after being called: kept' => [
        function (WaitlistEntry $entry) {
            app(WaitlistService::class)->callNextParties();
            app(WaitlistService::class)->leave($entry);
        },
        DepositOutcome::Forfeited,
    ],
    'missed the call: kept' => [
        function (WaitlistEntry $entry, $test) {
            app(WaitlistService::class)->callNextParties();
            $test->travel(6)->minutes();
            app(WaitlistService::class)->skipExpiredCalls();
        },
        DepositOutcome::Forfeited,
    ],
]);
