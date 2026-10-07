<?php

use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\WaitlistSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A valid form for joining the waitlist online.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function joinForm(array $overrides = []): array
{
    return [
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 5,
        'minutes' => 90,
        ...$overrides,
    ];
}

test('anyone can open the waitlist page without logging in', function () {
    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/join')
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6])
        ->where('checkInMinutes', 5)
        ->where('depositCents', 1000)
        ->where('currencySymbol', 'RM'));
});

test('the waitlist page asks for no deposit while the deposit is turned off', function () {
    app(WaitlistSettings::class)->requireDeposit(false);

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page->where('depositCents', 0));
});

test('with the deposit turned off joining puts a party that has to wait straight in line', function () {
    app(WaitlistSettings::class)->requireDeposit(false);
    laneInPlay();

    $response = $this->post(route('waitlist.store'), joinForm());

    $entry = WaitlistEntry::query()->where('minutes', 90)->sole();
    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->deposit->amount_cents)->toBe(0);
});

test('the waitlist page gives the likely wait for each session length', function () {
    $this->travelTo('2026-10-01 18:00:00');
    // The lane closes at 18:30 for a reservation from 19:30 to 20:30.
    reserveLanes(Lane::factory()->create(), now()->addMinutes(90), minutes: 60);

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('waitMinutes.30', 0)
        ->where('waitMinutes.60', 150)
        ->where('waitMinutes.240', 150));
});

test('joining saves the request and sends the party to pay, without putting it in line', function () {
    laneInPlay();

    $response = $this->post(route('waitlist.store'), joinForm());

    $deposit = WaitlistDeposit::query()->sole();
    $response->assertRedirect(route('waitlist.deposit.show', ['deposit' => $deposit->token]));
    expect($deposit->only(['name', 'phone', 'party_size', 'minutes', 'amount_cents', 'paid_at']))->toBe([
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 5,
        'minutes' => 90,
        'amount_cents' => 1000,
        'paid_at' => null,
    ]);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('joining while a lane is free puts the party in line with no deposit to pay', function () {
    Lane::factory()->create();

    $response = $this->post(route('waitlist.store'), joinForm());

    $entry = WaitlistEntry::query()->sole();
    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->customer->name)->toBe('Farah Aziz')
        ->and($entry->deposit->amount_cents)->toBe(0);
});

test('the waitlist page says whether any lane is open at all', function (Closure $lane, bool $open) {
    $lane();

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page->where('lanesOpen', $open));
})->with([
    'a lane is in play: open' => [fn () => laneInPlay(), true],
    'every lane is out of order: closed' => [fn () => Lane::factory()->outOfOrder()->create(), false],
]);

test('no one can join online while every lane is closed', function () {
    Lane::factory()->outOfOrder()->create();

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasErrors([
        'closed' => 'All our lanes are closed for maintenance right now. Please check back later, or ask our staff at the counter.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('no one can join online for a session no open lane has room for', function () {
    // The only lane is booked solid for longer than the estimate looks ahead.
    reserveLanes(Lane::factory()->create(), now(), minutes: 49 * 60);

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasErrors([
        'minutes' => 'We can\'t find a lane for that session right now. Try a shorter one, or ask our staff at the counter.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('while the venue is closed the page says so and when it opens', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 23:30'));

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('opening', ['isOpen' => false, 'opensAt' => '2026-10-06T02:00:00+00:00', 'closesAt' => null]));
});

test('no one can join the waitlist while the venue is closed', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 23:30'));

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasErrors(['closed' => 'We are closed right now. We open again tomorrow at 10:00 AM.']);
    $this->assertDatabaseCount('waitlist_deposits', 0);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('a party can join during opening hours', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 22:30'));
    laneInPlay();

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseCount('waitlist_deposits', 1);
});

test('the name, phone, party size and session length are required', function () {
    $response = $this->post(route('waitlist.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'The name field is required.',
        'phone' => 'The phone field is required.',
        'party_size' => 'The party size field is required.',
        'minutes' => 'The minutes field is required.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
});

test('a phone number with anything but digits is refused', function (string $phone) {
    $response = $this->post(route('waitlist.store'), joinForm(['phone' => $phone]));

    $response->assertSessionHasErrors([
        'phone' => 'The phone number can only contain digits, with no spaces, letters or symbols.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
})->with([
    'letters' => '01234abcde',
    'spaces' => '012 345 6789',
    'dashes' => '012-345-6789',
    'a plus sign' => '+60123456789',
    'brackets' => '(012)3456789',
]);

test('a group bigger than one lane is told to join again for the rest', function () {
    $response = $this->post(route('waitlist.store'), joinForm(['party_size' => 7]));

    $response->assertSessionHasErrors([
        'party_size' => 'A lane takes up to 6 people. Join again for the rest of your group.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
});

test('a session length that is not offered is refused', function () {
    $response = $this->post(route('waitlist.store'), joinForm(['minutes' => 45]));

    $response->assertSessionHasErrors('minutes');
    $this->assertDatabaseCount('waitlist_deposits', 0);
});

test('joining is refused after ten tries in a minute', function () {
    laneInPlay();
    foreach (range(1, 10) as $try) {
        $this->post(route('waitlist.store'), joinForm());
    }

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('waitlist_deposits', 10);
});
