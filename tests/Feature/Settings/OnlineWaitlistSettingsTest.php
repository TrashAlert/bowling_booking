<?php

use App\Models\User;
use App\Services\WaitlistSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('online-waitlist.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or close the online waitlist', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['online_open' => '0']);

    $response->assertForbidden();
    expect(app(WaitlistSettings::class)->onlineJoiningOpen())->toBeTrue();
})->with([
    'open the page' => ['get', 'online-waitlist.edit'],
    'change the setting' => ['put', 'online-waitlist.update'],
]);

test('admins see joining online open until someone closes it', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('online-waitlist.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/online-waitlist')
        ->where('onlineOpen', true)
        ->where('closedMessage', null)
        ->where('maxMessageLength', 200));
});

test('admins can close joining online with a message for customers', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '0', 'closed_message' => '  Closed tonight for a private event.  ']);

    $response->assertRedirect(route('online-waitlist.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Joining the waitlist online is closed. Customers are asked to come to the counter.']);
    expect(app(WaitlistSettings::class)->onlineStatus())->toBe(['open' => false, 'message' => 'Closed tonight for a private event.']);
});

test('admins can open joining online again, and a blank message goes back to the plain one', function () {
    app(WaitlistSettings::class)->setOnlineJoining(false, 'Closed tonight for a private event.');

    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '1', 'closed_message' => '']);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Customers can join the waitlist online.']);
    expect(app(WaitlistSettings::class)->onlineStatus())->toBe([
        'open' => true,
        'message' => 'We are not taking groups online right now. Please ask our staff at the counter.',
    ]);
});

test('a message longer than the limit is refused', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '0', 'closed_message' => str_repeat('a', 201)]);

    $response->assertSessionHasErrors(['closed_message' => 'The message field must not be greater than 200 characters.']);
    expect(app(WaitlistSettings::class)->onlineJoiningOpen())->toBeTrue();
});

test('groups must finish by closing time, with no limit on the wait, until an admin changes it', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('online-waitlist.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('finishByClosing', true)
        ->where('maxWaitMinutes', null)
        ->where('maxWaitLimits', ['min' => 10, 'max' => 600]));
});

test('admins can set the limits on joining online', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '1', 'finish_by_closing' => '0', 'max_wait_minutes' => '90']);

    $response->assertRedirect(route('online-waitlist.edit'));
    expect(app(WaitlistSettings::class)->joinLimits())->toBe(['finishByClosing' => false, 'maxWaitMinutes' => 90]);
});

test('an empty longest wait means no limit', function () {
    app(WaitlistSettings::class)->setJoinLimits(true, 90);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '1', 'finish_by_closing' => '1', 'max_wait_minutes' => '']);

    expect(app(WaitlistSettings::class)->maxWaitMinutes())->toBeNull();
});

test('a longest wait outside the limits is refused', function (int $minutes, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('online-waitlist.update'), ['online_open' => '1', 'finish_by_closing' => '1', 'max_wait_minutes' => $minutes]);

    $response->assertSessionHasErrors(['max_wait_minutes' => $message]);
    expect(app(WaitlistSettings::class)->maxWaitMinutes())->toBeNull();
})->with([
    'too short' => [5, 'The longest wait field must be at least 10.'],
    'too long' => [601, 'The longest wait field must not be greater than 600.'],
]);
