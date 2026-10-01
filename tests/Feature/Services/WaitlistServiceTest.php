<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Services\BookingCleanup;
use App\Services\LaneAvailability;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('joining as a new customer adds a waiting entry with a secret token', function () {
    $entry = app(WaitlistService::class)->joinAsNewCustomer('Farah', '0123456789', 90, 5);

    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->minutes)->toBe(90)
        ->and($entry->party_size)->toBe(5)
        ->and($entry->token)->toHaveLength(40)
        ->and($entry->toArray())->not->toHaveKey('token');

    $this->assertDatabaseHas('customers', ['id' => $entry->customer_id, 'name' => 'Farah', 'phone' => '0123456789']);
});

test('a waiting party is called and its lane held for five minutes', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toHaveCount(1);

    $entry->refresh();

    expect($entry->status)->toBe(WaitlistStatus::Called)
        ->and($entry->called_at->toDateTimeString())->toBe('2026-10-01 18:00:00')
        ->and($entry->booking->status)->toBe(BookingStatus::Pending)
        ->and($entry->booking->source)->toBe(BookingSource::WalkIn);

    expect($entry->booking->allocations->first())
        ->status->toBe(AllocationStatus::Held)
        ->and($entry->booking->allocations->first()->held_until->toDateTimeString())->toBe('2026-10-01 18:05:00')
        ->and($entry->booking->allocations->first()->ends_at->toDateTimeString())->toBe('2026-10-01 19:00:00');
});

test('a party is not called when its session would run into a reservation', function () {
    Lane::factory()->create();
    bookReservation(now()->addMinutes(30));
    $entry = joinWaitlist();

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toBeEmpty()
        ->and($entry->refresh()->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->booking_id)->toBeNull();
});

test('a party is called when its session ends just as a reservation starts', function () {
    Lane::factory()->create();
    bookReservation(now()->addMinutes(30));
    $entry = joinWaitlist(minutes: 30);

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toHaveCount(1)
        ->and($entry->refresh()->status)->toBe(WaitlistStatus::Called)
        ->and($entry->booking->minutes)->toBe(30)
        ->and($entry->booking->allocations->first()->ends_at->toDateTimeString())->toBe('2026-10-01 18:30:00');
});

test('a party that does not fit is passed over for one behind it that does', function () {
    Lane::factory()->create();
    $bigParty = joinWaitlist(partySize: 8);
    $smallParty = joinWaitlist(partySize: 2);

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toHaveCount(1)
        ->and($smallParty->refresh()->status)->toBe(WaitlistStatus::Called)
        ->and($bigParty->refresh()->status)->toBe(WaitlistStatus::Waiting);
});

test('nobody is called while every lane is busy', function () {
    Lane::factory()->create();
    bookReservation(now());
    $entry = joinWaitlist();

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toBeEmpty()
        ->and($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);
});

test('seating a called party starts its session', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    $this->travel(2)->minutes();

    $seated = app(WaitlistService::class)->seat($entry);

    expect($seated->status)->toBe(WaitlistStatus::Seated)
        ->and($seated->seated_at->toDateTimeString())->toBe('2026-10-01 18:02:00')
        ->and($seated->booking->status)->toBe(BookingStatus::CheckedIn);

    expect($seated->booking->allocations->first())
        ->status->toBe(AllocationStatus::Active)
        ->held_until->toBeNull();
});

test('a party that was never called cannot be seated', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();

    expect(fn () => app(WaitlistService::class)->seat($entry))
        ->toThrow(InvalidStateException::class);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);
});

test('a party cannot be seated after its call expires', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    $this->travel(6)->minutes();
    app(WaitlistService::class)->skipExpiredCalls();

    expect(fn () => app(WaitlistService::class)->seat($entry))
        ->toThrow(InvalidStateException::class);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Skipped)
        ->and($entry->booking->status)->toBe(BookingStatus::Cancelled);
});

test('a party cannot be seated once its held lane has been released', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    $this->travel(6)->minutes();
    app(BookingCleanup::class)->releaseExpiredHolds();

    expect(fn () => app(WaitlistService::class)->seat($entry))
        ->toThrow(InvalidStateException::class);

    expect($entry->refresh()->booking->status)->toBe(BookingStatus::Cancelled);
});

test('a call is only skipped once the check-in window has passed', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    $this->travel(4)->minutes();

    $skipped = app(WaitlistService::class)->skipExpiredCalls();

    expect($skipped)->toBe(0)
        ->and($entry->refresh()->status)->toBe(WaitlistStatus::Called);
});

test('skipping a called party frees its lane', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();

    app(WaitlistService::class)->skip($entry);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Skipped)
        ->and($entry->booking->status)->toBe(BookingStatus::Cancelled)
        ->and($entry->booking->allocations->first()->status)->toBe(AllocationStatus::Released)
        ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1);
});

test('a party that leaves is taken out of the line', function () {
    $leaving = joinWaitlist();
    $staying = joinWaitlist();

    app(WaitlistService::class)->leave($leaving);

    expect($leaving->refresh()->status)->toBe(WaitlistStatus::Left)
        ->and($leaving->position())->toBeNull()
        ->and($staying->refresh()->position())->toBe(1);
});

test('a party that is already seated is not skipped', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    app(WaitlistService::class)->callNextParties();
    app(WaitlistService::class)->seat($entry);

    app(WaitlistService::class)->skip($entry);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated)
        ->and($entry->booking->status)->toBe(BookingStatus::CheckedIn);
});
