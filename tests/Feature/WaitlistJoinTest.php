<?php

use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Services\WaitlistSettings;
use Illuminate\Testing\TestResponse;
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

/**
 * Join the waitlist from the phone that made the earlier join, by sending
 * back the cookie that join handed it.
 *
 * @param  array<string, mixed>  $overrides
 */
function joinFromSamePhone(TestResponse $earlier, array $overrides = []): TestResponse
{
    return test()
        ->withCookie('waitlist_groups', $earlier->getCookie('waitlist_groups')->getValue())
        ->post(route('waitlist.store'), joinForm($overrides));
}

test('anyone can open the waitlist page without logging in', function () {
    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/join')
        ->where('session', ['stepMinutes' => 30, 'maxMinutes' => 240, 'maxPlayersPerLane' => 6, 'mode' => 'time', 'minutesPerPlayerGame' => 10, 'maxGames' => 10])
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

test('while joining online is closed the page says so with the admin\'s message', function () {
    app(WaitlistSettings::class)->setOnlineJoining(false, 'Closed tonight for a private event.');

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('online', ['open' => false, 'message' => 'Closed tonight for a private event.']));
});

test('no one can join online while an admin has closed it', function () {
    Lane::factory()->create();
    app(WaitlistSettings::class)->setOnlineJoining(false, null);

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasErrors(['closed' => 'We are not taking groups online right now. Please ask our staff at the counter.']);
    $this->assertDatabaseCount('waitlist_deposits', 0);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('a party can join during opening hours', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 20:00'));
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

test('a phone number shorter than ten digits is refused', function () {
    $response = $this->post(route('waitlist.store'), joinForm(['phone' => '012345678']));

    $response->assertSessionHasErrors([
        'phone' => 'The phone number must have at least 10 digits.',
    ]);
    $this->assertDatabaseCount('waitlist_deposits', 0);
});

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

test('joining is refused after five tries in a minute', function () {
    laneInPlay();
    foreach (range(1, 5) as $try) {
        $this->post(route('waitlist.store'), joinForm());
    }

    $response = $this->post(route('waitlist.store'), joinForm());

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('waitlist_deposits', 5);
});

test('one phone can only have two groups in the line at once', function () {
    Lane::factory()->count(3)->create();
    $first = $this->post(route('waitlist.store'), joinForm(['name' => 'First group']));
    $second = joinFromSamePhone($first, ['name' => 'Second group']);

    $third = joinFromSamePhone($second, ['name' => 'Third group']);

    $third->assertSessionHasErrors([
        'limit' => 'This phone already has 2 groups in the line. Leave one of them first, or ask our staff at the counter to add your group.',
    ]);
    $this->assertDatabaseCount('waitlist_entries', 2);
    $this->assertDatabaseMissing('customers', ['name' => 'Third group']);
});

test('a phone can join again once one of its groups has left the line', function () {
    Lane::factory()->count(3)->create();
    $first = $this->post(route('waitlist.store'), joinForm(['name' => 'First group']));
    $second = joinFromSamePhone($first, ['name' => 'Second group']);
    app(WaitlistService::class)->leave(WaitlistEntry::query()->oldest('id')->first());

    $third = joinFromSamePhone($second, ['name' => 'Third group']);

    $third->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customers', ['name' => 'Third group']);
});

test('another phone is not held back by the first one\'s groups', function () {
    Lane::factory()->count(3)->create();
    $first = $this->post(route('waitlist.store'), joinForm());
    joinFromSamePhone($first);
    // A phone that has never joined sends no cookie.
    $this->defaultCookies = [];

    $response = $this->post(route('waitlist.store'), joinForm(['name' => 'Someone else']));

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseCount('waitlist_entries', 3);
});

test('a deposit that was never paid does not count against a phone', function () {
    laneInPlay();
    $first = $this->post(route('waitlist.store'), joinForm());
    $second = joinFromSamePhone($first);

    $third = joinFromSamePhone($second);

    $third->assertSessionHasNoErrors();
    $this->assertDatabaseCount('waitlist_deposits', 3);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('the waitlist page tells a phone when it has reached its limit', function () {
    Lane::factory()->count(3)->create();
    $this->get(route('waitlist.join'))
        ->assertInertia(fn (Assert $page) => $page->where('deviceLimit', ['max' => 2, 'reached' => false]));
    $first = $this->post(route('waitlist.store'), joinForm());
    $second = joinFromSamePhone($first);

    $response = $this->withCookie('waitlist_groups', $second->getCookie('waitlist_groups')->getValue())
        ->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page->where('deviceLimit', ['max' => 2, 'reached' => true]));
});

test('a cookie that is not the one we handed out counts for nothing', function () {
    Lane::factory()->count(3)->create();

    $response = $this->withCookie('waitlist_groups', 'not a list')->post(route('waitlist.store'), joinForm());

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseCount('waitlist_entries', 1);
});

test('a group can join online for games, and its wait is for the time they take', function () {
    config(['bowling.session_mode' => 'games']);
    Lane::factory()->create();

    $response = $this->post(route('waitlist.store'), joinForm(['minutes' => null, 'party_size' => 4, 'games' => 3]));

    $entry = WaitlistEntry::query()->sole();
    $response->assertRedirect(route('waitlist.show', $entry->token));
    expect([$entry->games, $entry->minutes, $entry->deposit->games])->toBe([3, 120, 3]);
});

test('the join page knows the wait for the longest time games can take', function () {
    config(['bowling.session_mode' => 'both']);
    Lane::factory()->create();

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('session.mode', 'both')
        ->where('waitMinutes.600', 0)
        ->missing('waitMinutes.630'));
});

describe('the last call before closing', function () {
    beforeEach(function () {
        openDaily('10:00', '23:00');
    });

    test('a group can only join for a session that ends by closing time', function (int $minutes, bool $allowed) {
        $this->travelTo(venueTime('2026-10-05 22:00'));
        Lane::factory()->create();

        $response = $this->post(route('waitlist.store'), joinForm(['minutes' => $minutes]));

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseCount('waitlist_entries', 1);
        } else {
            $response->assertSessionHasErrors(['closed' => 'We close at 11:00 PM, and a session of 1 hour 30 minutes joined now would end after that. Pick a shorter session, or ask our staff at the counter.']);
            $this->assertDatabaseCount('waitlist_deposits', 0);
        }
    })->with([
        'ending at closing time' => [60, true],
        'ending after closing time' => [90, false],
    ]);

    test('the wait counts toward the last call', function (int $minutes, bool $allowed) {
        $this->travelTo(venueTime('2026-10-05 21:00'));
        // The only lane is free again at 10 PM.
        laneInPlay();

        $response = $this->post(route('waitlist.store'), joinForm(['minutes' => $minutes]));

        $allowed
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('closed');
    })->with([
        'from 10 PM for an hour' => [60, true],
        'from 10 PM for an hour and a half' => [90, false],
    ]);

    test('with the last call turned off a group can join for any length', function () {
        app(WaitlistSettings::class)->setJoinLimits(false, null);
        $this->travelTo(venueTime('2026-10-05 22:55'));
        Lane::factory()->create();

        $response = $this->post(route('waitlist.store'), joinForm(['minutes' => 240]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('waitlist_entries', 1);
    });
});

describe('the longest wait for joining online', function () {
    test('no group can join online while its wait is longer than the limit', function () {
        app(WaitlistSettings::class)->setJoinLimits(true, 30);
        $this->travelTo('2026-10-01 18:00:00');
        laneInPlay();

        $response = $this->post(route('waitlist.store'), joinForm(['minutes' => 60]));

        $response->assertSessionHasErrors(['closed' => 'The wait is over 30 minutes right now, so we are not adding more groups online. Please ask our staff at the counter.']);
        $this->assertDatabaseCount('waitlist_deposits', 0);
    });

    test('a group whose wait is within the limit can join', function () {
        app(WaitlistSettings::class)->setJoinLimits(true, 60);
        $this->travelTo('2026-10-01 18:00:00');
        laneInPlay();

        $response = $this->post(route('waitlist.store'), joinForm(['minutes' => 60]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('waitlist_deposits', 1);
    });

    test('a group that comes back to pay is checked against the limit again', function () {
        $this->travelTo('2026-10-01 18:00:00');
        laneInPlay();
        $this->post(route('waitlist.store'), joinForm(['minutes' => 60]));
        $deposit = WaitlistDeposit::query()->sole();
        app(WaitlistSettings::class)->setJoinLimits(true, 30);

        $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

        $response->assertSessionHasErrors(['closed' => 'The wait is over 30 minutes right now, so we are not adding more groups online. Please ask our staff at the counter.']);
        expect($deposit->refresh()->isPaid())->toBeFalse();
    });
});

test('the join page knows the limits on joining', function () {
    app(WaitlistSettings::class)->setJoinLimits(false, 45);

    $response = $this->get(route('waitlist.join'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('joinLimits', ['finishByClosing' => false, 'maxWaitMinutes' => 45]));
});
