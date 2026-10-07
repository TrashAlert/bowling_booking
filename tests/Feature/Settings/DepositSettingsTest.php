<?php

use App\Models\User;
use App\Services\WaitlistSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('deposit.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the deposit setting', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['deposit_required' => '0']);

    $response->assertForbidden();
    expect(app(WaitlistSettings::class)->depositRequired())->toBeTrue();
})->with([
    'open the page' => ['get', 'deposit.edit'],
    'change the setting' => ['put', 'deposit.update'],
]);

test('admins see that the deposit is on until someone turns it off', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('deposit.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/deposit')
        ->where('depositRequired', true)
        ->where('depositCents', 1000));
});

test('admins can turn the deposit off and on again', function (bool $startsOn, string $sent, bool $required, string $message) {
    app(WaitlistSettings::class)->requireDeposit($startsOn);

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('deposit.update'), ['deposit_required' => $sent]);

    $response->assertRedirect(route('deposit.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
    expect(app(WaitlistSettings::class)->depositRequired())->toBe($required);
    $this->assertDatabaseCount('settings', 1);
})->with([
    'off' => [true, '0', false, 'Customers can now join the waitlist online without a deposit.'],
    'on again' => [false, '1', true, 'Customers now pay a deposit to join online when no lane is free.'],
]);

test('the deposit choice is required and must be yes or no', function (array $payload, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())->put(route('deposit.update'), $payload);

    $response->assertSessionHasErrors(['deposit_required' => $message]);
    $this->assertDatabaseCount('settings', 0);
})->with([
    'missing' => [[], 'The deposit choice field is required.'],
    'not yes or no' => [['deposit_required' => 'maybe'], 'The deposit choice field must be true or false.'],
]);
