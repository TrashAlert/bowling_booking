<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
});

test('staff who change their own email keep their way into the staff area', function (Closure $user) {
    $user = $user();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new-address@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
    $this->actingAs($user)->get(route('staff.board'))->assertOk();
})->with([
    'counter staff' => [fn () => User::factory()->staff()->create()],
    'an admin' => [fn () => User::factory()->admin()->create()],
]);

test('an email typed with capital letters is saved in lowercase, so it still works at login', function () {
    $user = User::factory()->staff()->create();
    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'Farah.Aziz@Example.com']);
    auth()->logout();

    $response = $this->post(route('login.store'), ['email' => 'Farah.Aziz@Example.com', 'password' => 'password']);

    expect($user->refresh()->email)->toBe('farah.aziz@example.com');
    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('a new email that only differs in capitals from someone else\'s is refused', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->staff()->create();

    $response = $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'Taken@Example.com']);

    $response->assertSessionHasErrors('email');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('nobody can delete their own account', function (Closure $user) {
    $user = $user();

    $response = $this
        ->actingAs($user)
        ->delete('/settings/profile', [
            'password' => 'password',
        ]);

    $response->assertMethodNotAllowed();
    $this->assertModelExists($user);
})->with([
    'admin' => [fn () => User::factory()->admin()->create()],
    'staff' => [fn () => User::factory()->staff()->create()],
]);
