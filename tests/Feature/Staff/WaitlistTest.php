<?php

use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;

describe('adding a walk-in', function () {
    test('staff can add a walk-in party to the line', function () {
        Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Farah',
            'phone' => '0123456789',
            'party_size' => 4,
            'minutes' => 90,
        ]);

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah is in line.']);

        $entry = WaitlistEntry::query()->sole();

        expect($entry->status)->toBe(WaitlistStatus::Waiting)
            ->and($entry->party_size)->toBe(4)
            ->and($entry->minutes)->toBe(90)
            ->and($entry->customer->name)->toBe('Farah')
            ->and($entry->customer->phone)->toBe('0123456789');
    });

    test('a walk-in can be added without a phone number', function () {
        Lane::factory()->create();

        $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Farah',
            'party_size' => 2,
            'minutes' => 60,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['name' => 'Farah', 'phone' => null]);
    });

    test('the name, party size and session length are required', function () {
        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), []);

        $response->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'party_size' => 'The party size field is required.',
            'minutes' => 'The minutes field is required.',
        ]);
        $this->assertDatabaseCount('waitlist_entries', 0);
        $this->assertDatabaseCount('customers', 0);
    });

    test('the party size must be at least one', function () {
        Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Farah',
            'party_size' => 0,
            'minutes' => 60,
        ]);

        $response->assertSessionHasErrors(['party_size' => 'The party size field must be at least 1.']);
        $this->assertDatabaseCount('waitlist_entries', 0);
    });

    test('the shortest and the longest session can be chosen', function (int $minutes) {
        Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Farah',
            'party_size' => 4,
            'minutes' => $minutes,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('waitlist_entries', ['minutes' => $minutes]);
    })->with([
        'thirty minutes' => 30,
        'four hours' => 240,
    ]);

    test('the session length must be in half-hour steps within the limits', function (int $minutes, string $message) {
        Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Farah',
            'party_size' => 4,
            'minutes' => $minutes,
        ]);

        $response->assertSessionHasErrors(['minutes' => $message]);
        $this->assertDatabaseCount('waitlist_entries', 0);
    })->with([
        'shorter than 30 minutes' => [0, 'The minutes field must be at least 30.'],
        'not a half-hour step' => [45, 'The minutes field must be a multiple of 30.'],
        'longer than 4 hours' => [270, 'The minutes field must not be greater than 240.'],
    ]);

    test('a party that needs more lanes than the venue has is turned away', function () {
        Lane::factory()->count(2)->create();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.store'), [
            'name' => 'Office party',
            'party_size' => 13,
            'minutes' => 60,
        ]);

        $response->assertSessionHasErrors(['party_size' => 'A party this size needs 3 lanes, but there are only 2.']);
        $this->assertDatabaseCount('waitlist_entries', 0);
    });
});

describe('calling the next party', function () {
    test('staff can call the next party without waiting for the scheduler', function () {
        Lane::factory()->create();
        $entry = joinWaitlist();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.call-next'));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Called 1 party.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Called);
    });

    test('staff are told when no waiting party fits a free lane', function () {
        $entry = joinWaitlist();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.call-next'));

        $response->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No waiting party fits a free lane right now.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);
    });
});

describe('seating a party', function () {
    test('staff can seat a called party', function () {
        Lane::factory()->create();
        $entry = joinWaitlist(name: 'Farah');
        app(WaitlistService::class)->callNextParties();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.seat', $entry));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah is seated.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
    });

    test('staff are told when the call has already expired', function () {
        $this->travelTo('2026-10-01 18:00:00');
        Lane::factory()->create();
        $entry = joinWaitlist();
        app(WaitlistService::class)->callNextParties();
        $this->travel(6)->minutes();
        app(WaitlistService::class)->skipExpiredCalls();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.seat', $entry));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'This party is not currently called, or their call has expired.',
            ]);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Skipped);
    });
});

describe('removing a party', function () {
    test('staff can skip a called party that did not turn up', function () {
        Lane::factory()->create();
        $entry = joinWaitlist(name: 'Farah');
        app(WaitlistService::class)->callNextParties();

        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.skip', $entry));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah was skipped.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Skipped);
    });

    test('staff can remove a waiting party from the line', function () {
        $entry = joinWaitlist(name: 'Farah');

        $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.waitlist.destroy', $entry));

        $response->assertRedirect(route('staff.board'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Farah was removed from the line.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Left);
    });

    test('removing a party that was seated in the meantime changes nothing', function () {
        Lane::factory()->create();
        $entry = joinWaitlist(name: 'Farah');
        app(WaitlistService::class)->callNextParties();
        app(WaitlistService::class)->seat($entry);

        $response = $this->actingAs(User::factory()->staff()->create())->delete(route('staff.waitlist.destroy', $entry));

        $response->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Farah is no longer in line.']);
        expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
    });

    test('a party that does not exist returns 404', function () {
        $response = $this->actingAs(User::factory()->staff()->create())->post(route('staff.waitlist.seat', 999));

        $response->assertNotFound();
    });
});
