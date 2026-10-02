<?php

use App\Models\WaitlistDeposit;
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

test('joining saves the request and sends the party to pay, without putting it in line', function () {
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
    foreach (range(1, 10) as $try) {
        $this->post(route('waitlist.store'), joinForm());
    }

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('waitlist_deposits', 10);
});
