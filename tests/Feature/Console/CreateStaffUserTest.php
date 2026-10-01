<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified staff user who can log in', function () {
    $this->artisan('staff:create', [
        'name' => 'Aina Counter',
        'email' => 'aina@example.com',
        '--password' => 'correct-horse-battery',
    ])
        ->expectsOutput('Created staff user aina@example.com.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'aina@example.com')->sole();

    expect($user->name)->toBe('Aina Counter')
        ->and($user->role)->toBe(UserRole::Staff)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

test('it creates an admin with the admin option', function () {
    $this->artisan('staff:create', [
        'name' => 'Olivia Owner',
        'email' => 'olivia@example.com',
        '--password' => 'correct-horse-battery',
        '--admin' => true,
    ])
        ->expectsOutput('Created admin user olivia@example.com.')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', [
        'email' => 'olivia@example.com',
        'role' => 'admin',
    ]);
});

test('it stores the email in lowercase so the login form can find it', function () {
    $this->artisan('staff:create', [
        'name' => 'Aina Counter',
        'email' => 'Aina@Example.com',
        '--password' => 'correct-horse-battery',
    ])->assertSuccessful();

    $response = $this->post(route('login.store'), [
        'email' => 'Aina@Example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

test('it asks for the password when the option is left out', function () {
    $this->artisan('staff:create', [
        'name' => 'Aina Counter',
        'email' => 'aina@example.com',
    ])
        ->expectsQuestion('Password', 'typed-at-the-prompt')
        ->assertSuccessful();

    $user = User::query()->where('email', 'aina@example.com')->sole();

    expect(Hash::check('typed-at-the-prompt', $user->password))->toBeTrue();
});

test('it rejects an email address that is already in use', function () {
    User::factory()->create(['email' => 'aina@example.com']);

    $this->artisan('staff:create', [
        'name' => 'Aina Counter',
        'email' => 'aina@example.com',
        '--password' => 'correct-horse-battery',
    ])
        ->expectsOutput('The email has already been taken.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 1);
});

test('it rejects an empty password', function () {
    $this->artisan('staff:create', [
        'name' => 'Aina Counter',
        'email' => 'aina@example.com',
    ])
        ->expectsQuestion('Password', '')
        ->expectsOutput('The password field is required.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
});
