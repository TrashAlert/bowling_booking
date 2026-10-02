<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('the login screen offers no way to reset a forgotten password', function () {
    $response = $this->get(route('login'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/login')
        ->missing('canResetPassword'));
});

test('the pages for resetting a password by email are gone', function (string $method, string $uri) {
    $response = $this->{$method}($uri, ['email' => 'someone@example.com']);

    $response->assertNotFound();
})->with([
    'the forgot password form' => ['get', '/forgot-password'],
    'asking for a reset link' => ['post', '/forgot-password'],
    'the new password form' => ['get', '/reset-password/some-token'],
    'setting a new password' => ['post', '/reset-password'],
]);

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('a user who once turned two-factor on logs in without being asked for a code', function () {
    $user = User::factory()->staff()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertSessionMissing('login.id');
});

test('the two-factor pages are gone', function (string $method, string $uri) {
    $response = $this->actingAs(User::factory()->admin()->create())->{$method}($uri);

    $response->assertNotFound();
})->with([
    'the code prompt at login' => ['get', '/two-factor-challenge'],
    'turning two-factor on' => ['post', '/user/two-factor-authentication'],
    'the setup QR code' => ['get', '/user/two-factor-qr-code'],
    'the recovery codes' => ['get', '/user/two-factor-recovery-codes'],
]);

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
