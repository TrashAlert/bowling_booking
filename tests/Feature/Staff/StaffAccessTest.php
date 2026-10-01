<?php

use App\Models\Lane;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('staff.board'));

    $response->assertRedirect(route('login'));
});

test('users without a role are forbidden from every staff endpoint', function (string $method, Closure $url) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->{$method}($url());

    $response->assertForbidden();
})->with([
    'lane board' => ['get', fn () => route('staff.board')],
    'add walk-in' => ['post', fn () => route('staff.waitlist.store')],
    'call next' => ['post', fn () => route('staff.waitlist.call-next')],
    'seat' => ['post', fn () => route('staff.waitlist.seat', joinWaitlist())],
    'skip' => ['post', fn () => route('staff.waitlist.skip', joinWaitlist())],
    'remove' => ['delete', fn () => route('staff.waitlist.destroy', joinWaitlist())],
    'check in' => ['post', function () {
        Lane::factory()->create();

        return route('staff.bookings.check-in', bookReservation(now()));
    }],
    'toggle lane' => ['patch', fn () => route('staff.lanes.update', Lane::factory()->create())],
    'list reservations' => ['get', fn () => route('staff.reservations.index')],
    'make a reservation' => ['post', fn () => route('staff.reservations.store')],
    'change a reservation' => ['patch', fn () => route('staff.reservations.update', reserveLanes(Lane::factory()->create(), now()->addHours(2)))],
    'cancel a reservation' => ['delete', fn () => route('staff.reservations.destroy', reserveLanes(Lane::factory()->create(), now()->addHours(2)))],
]);

test('staff and admins can visit the staff area', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $response = $this->actingAs($user)->get(route('staff.board'));

    $response->assertOk();
})->with([
    'staff' => 'staff',
    'admin' => 'admin',
]);

test('unverified staff are sent to verify their email first', function () {
    $user = User::factory()->staff()->unverified()->create();

    $response = $this->actingAs($user)->get(route('staff.board'));

    $response->assertRedirect(route('verification.notice'));
});

test('admins count as staff and staff are not admins', function () {
    $staff = User::factory()->staff()->make();
    $admin = User::factory()->admin()->make();
    $user = User::factory()->make();

    expect($staff->isStaff())->toBeTrue()
        ->and($staff->isAdmin())->toBeFalse()
        ->and($admin->isStaff())->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($user->isStaff())->toBeFalse()
        ->and($user->isAdmin())->toBeFalse();
});

test('the role cannot be set through the profile form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'admin',
    ]);

    expect($user->refresh()->role)->toBeNull();
});
