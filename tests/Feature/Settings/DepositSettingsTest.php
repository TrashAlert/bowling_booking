<?php

use App\Models\User;
use App\Models\WaitlistDeposit;
use App\Services\WaitlistSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('deposit.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the deposit setting', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['deposit_required' => '0', 'deposit_amount' => '99']);

    $response->assertForbidden();
    expect(app(WaitlistSettings::class)->depositRequired())->toBeTrue()
        ->and(config('bowling.waitlist_deposit_cents'))->toBe(1000);
})->with([
    'open the page' => ['get', 'deposit.edit'],
    'change the setting' => ['put', 'deposit.update'],
]);

test('admins see that the deposit is on until someone turns it off', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('deposit.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/deposit')
        ->where('depositRequired', true)
        ->where('depositCents', 1000)
        ->where('limits', ['minAmount' => 1, 'maxAmount' => 1000]));
});

test('admins can turn the deposit off and on again', function (bool $startsOn, string $sent, bool $required, string $message) {
    app(WaitlistSettings::class)->requireDeposit($startsOn);

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('deposit.update'), ['deposit_required' => $sent, 'deposit_amount' => '10']);

    $response->assertRedirect(route('deposit.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
    expect(app(WaitlistSettings::class)->depositRequired())->toBe($required);
})->with([
    'off' => [true, '0', false, 'Customers can now join the waitlist online without a deposit.'],
    'on again' => [false, '1', true, 'Customers now pay a RM 10.00 deposit to join online when no lane is free.'],
]);

test('admins can change the deposit amount, which the next party to join is asked for', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('deposit.update'), ['deposit_required' => '1', 'deposit_amount' => '15.50']);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Customers now pay a RM 15.50 deposit to join online when no lane is free.']);
    $this->get(route('deposit.edit'))->assertInertia(fn (Assert $page) => $page->where('depositCents', 1550));
    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page->where('depositCents', 1550));

    laneInPlay();
    $this->post(route('waitlist.store'), ['name' => 'Farah', 'phone' => '0123456789', 'party_size' => 2, 'minutes' => 60]);

    expect(WaitlistDeposit::query()->sole()->amount_cents)->toBe(1550);
});

test('a party already asked for a deposit keeps the amount it was shown', function () {
    $asked = WaitlistDeposit::factory()->create(['amount_cents' => 1000]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('deposit.update'), ['deposit_required' => '1', 'deposit_amount' => '25']);

    expect($asked->refresh()->amount_cents)->toBe(1000);
});

test('deposit settings that are not acceptable are refused', function (array $payload, string $field, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('deposit.update'), ['deposit_required' => '1', 'deposit_amount' => '10', ...$payload]);

    $response->assertSessionHasErrors([$field => $message]);
    $this->assertDatabaseCount('settings', 0);
})->with([
    'no choice' => [['deposit_required' => ''], 'deposit_required', 'The deposit choice field is required.'],
    'a choice that is not yes or no' => [['deposit_required' => 'maybe'], 'deposit_required', 'The deposit choice field must be true or false.'],
    'no amount' => [['deposit_amount' => ''], 'deposit_amount', 'The deposit amount field is required.'],
    'an amount that is not a number' => [['deposit_amount' => 'ten'], 'deposit_amount', 'The deposit amount field must be a number.'],
    'an amount with fractions of a cent' => [['deposit_amount' => '10.555'], 'deposit_amount', 'The deposit amount field must have 0-2 decimal places.'],
    'an amount below the least allowed' => [['deposit_amount' => '0.50'], 'deposit_amount', 'The deposit amount field must be at least 1.'],
    'an amount above the most allowed' => [['deposit_amount' => '1000.01'], 'deposit_amount', 'The deposit amount field must not be greater than 1000.'],
]);
