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
