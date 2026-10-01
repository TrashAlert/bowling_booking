<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('users.index'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot manage users', function (string $method, Closure $url) {
    $staff = User::factory()->staff()->create();

    $response = $this->actingAs($staff)->{$method}($url(), [
        'name' => 'Changed',
        'email' => 'changed@example.com',
        'role' => 'admin',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'changed@example.com']);
})->with([
    'list users' => ['get', fn () => route('users.index')],
    'add a user' => ['post', fn () => route('users.store')],
    'edit a user' => ['patch', fn () => route('users.update', User::factory()->create())],
    'remove a user' => ['delete', fn () => route('users.destroy', User::factory()->create())],
]);

test('admins see every user with only their name, email and role', function () {
    $admin = User::factory()->admin()->create(['name' => 'Zara Owner', 'email' => 'zara@example.com']);
    $staff = User::factory()->staff()->create(['name' => 'Aina Counter', 'email' => 'aina@example.com']);

    $response = $this->actingAs($admin)->get(route('users.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/users')
        ->where('users', [
            ['id' => $staff->id, 'name' => 'Aina Counter', 'email' => 'aina@example.com', 'role' => 'staff'],
            ['id' => $admin->id, 'name' => 'Zara Owner', 'email' => 'zara@example.com', 'role' => 'admin'],
        ]));
});

describe('adding a user', function () {
    test('admins can add a user who can log in straight away', function () {
        $response = $this->actingAs(User::factory()->admin()->create())->post(route('users.store'), [
            'name' => 'Aina Counter',
            'email' => 'Aina@Example.com',
            'role' => 'staff',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('users.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aina Counter was added.']);

        $user = User::query()->where('email', 'aina@example.com')->sole();

        expect($user->name)->toBe('Aina Counter')
            ->and($user->role)->toBe(UserRole::Staff)
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
    });

    test('the name, email, role and password are required', function () {
        $response = $this->actingAs(User::factory()->admin()->create())->post(route('users.store'), []);

        $response->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'email' => 'The email field is required.',
            'role' => 'The role field is required.',
            'password' => 'The password field is required.',
        ]);
        $this->assertDatabaseCount('users', 1);
    });

    test('a new user is refused when a detail is not acceptable', function (array $override, string $field, string $message) {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs(User::factory()->admin()->create())->post(route('users.store'), [
            'name' => 'Aina Counter',
            'email' => 'aina@example.com',
            'role' => 'staff',
            'password' => 'correct-horse-battery',
            ...$override,
        ]);

        $response->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('users', 2);
    })->with([
        'email already in use, whatever its case' => [['email' => 'Taken@Example.com'], 'email', 'The email has already been taken.'],
        'unknown role' => [['role' => 'owner'], 'role', 'The selected role is invalid.'],
        'password too short' => [['password' => 'short'], 'password', 'The password field must be at least 8 characters.'],
    ]);
});

describe('editing a user', function () {
    test('admins can change a user\'s name, email and role without touching the password', function () {
        $user = User::factory()->staff()->create();
        $oldPassword = $user->password;

        $response = $this->actingAs(User::factory()->admin()->create())->patch(route('users.update', $user), [
            'name' => 'Aina Manager',
            'email' => 'Aina.Manager@Example.com',
            'role' => 'admin',
            'password' => '',
        ]);

        $response->assertRedirect(route('users.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aina Manager was updated.']);

        $user->refresh();

        expect($user->name)->toBe('Aina Manager')
            ->and($user->email)->toBe('aina.manager@example.com')
            ->and($user->role)->toBe(UserRole::Admin)
            ->and($user->password)->toBe($oldPassword)
            ->and($user->email_verified_at)->not->toBeNull();
    });

    test('admins can set a new password for a user', function () {
        $user = User::factory()->staff()->create();

        $this->actingAs(User::factory()->admin()->create())->patch(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'staff',
            'password' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        expect(Hash::check('a-brand-new-password', $user->refresh()->password))->toBeTrue();
    });

    test('a user can keep their own email when edited', function () {
        $user = User::factory()->staff()->create(['email' => 'aina@example.com']);

        $response = $this->actingAs(User::factory()->admin()->create())->patch(route('users.update', $user), [
            'name' => 'Aina Renamed',
            'email' => 'aina@example.com',
            'role' => 'staff',
        ]);

        $response->assertSessionHasNoErrors();
        expect($user->refresh()->name)->toBe('Aina Renamed');
    });

    test('a user cannot be given an email that belongs to someone else', function () {
        $user = User::factory()->staff()->create(['email' => 'aina@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs(User::factory()->admin()->create())->patch(route('users.update', $user), [
            'name' => $user->name,
            'email' => 'taken@example.com',
            'role' => 'staff',
        ]);

        $response->assertSessionHasErrors(['email' => 'The email has already been taken.']);
        expect($user->refresh()->email)->toBe('aina@example.com');
    });

    test('admins cannot change their own role', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'staff',
        ]);

        $response->assertSessionHasErrors(['role' => "You can't change your own role."]);
        expect($admin->refresh()->role)->toBe(UserRole::Admin);
    });

    test('admins can change their own name while keeping their role', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(route('users.update', $admin), [
            'name' => 'Olivia Owner',
            'email' => $admin->email,
            'role' => 'admin',
        ]);

        $response->assertSessionHasNoErrors();
        expect($admin->refresh()->name)->toBe('Olivia Owner');
    });
});

describe('removing a user', function () {
    test('admins can remove another user', function () {
        $user = User::factory()->staff()->create(['name' => 'Aina Counter']);

        $response = $this->actingAs(User::factory()->admin()->create())->delete(route('users.destroy', $user));

        $response->assertRedirect(route('users.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aina Counter was removed.']);
        $this->assertModelMissing($user);
    });

    test('admins cannot remove their own account from the users page', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->delete(route('users.destroy', $admin));

        $response->assertForbidden();
        $this->assertModelExists($admin);
    });

    test('removing a user that does not exist returns 404', function () {
        $response = $this->actingAs(User::factory()->admin()->create())->delete(route('users.destroy', 999999));

        $response->assertNotFound();
    });
});
