<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('pages are not told about a demo outside demo mode', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('demo', null));
});

test('in demo mode every page has the demo logins and when it starts afresh', function () {
    config(['bowling.demo' => true]);

    $response = $this->get(route('login'));

    $response->assertInertia(fn (Assert $page) => $page->where('demo', [
        'logins' => [
            ['role' => 'Owner (admin)', 'email' => 'owner@demo.test', 'password' => 'demo-owner'],
            ['role' => 'Counter staff', 'email' => 'counter@demo.test', 'password' => 'demo-counter'],
        ],
        'resetsAt' => '09:00',
    ]));
});

describe('in demo mode', function () {
    beforeEach(function () {
        config(['bowling.demo' => true]);
        $this->admin = User::factory()->admin()->create(['name' => 'Aisyah', 'email' => 'owner@demo.test']);
        $this->counter = User::factory()->staff()->create(['name' => 'Counter']);
    });

    test('nobody can change who can log in, or a login\'s details', function (Closure $send) {
        $before = User::query()->orderBy('id')->get(['id', 'name', 'email', 'password', 'role'])->toArray();

        $response = $send($this->actingAs($this->admin), $this->counter);

        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This is turned off in the demo, so every visitor can keep using the same logins.']);
        expect(User::query()->orderBy('id')->get(['id', 'name', 'email', 'password', 'role'])->toArray())->toBe($before);
    })->with([
        'add a user' => [fn ($test) => $test->post(route('users.store'), ['name' => 'Intruder', 'email' => 'intruder@example.com', 'role' => 'admin', 'password' => 'Secret-password-123', 'password_confirmation' => 'Secret-password-123'])],
        'change a user' => [fn ($test, User $counter) => $test->patch(route('users.update', $counter), ['name' => 'Renamed', 'email' => $counter->email, 'role' => 'admin'])],
        'remove a user' => [fn ($test, User $counter) => $test->delete(route('users.destroy', $counter))],
        'change your own name or email' => [fn ($test) => $test->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'new@example.com'])],
        'change your own password' => [fn ($test) => $test->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])],
    ]);

    test('the logo and cover photo cannot be uploaded', function (string $route, string $field) {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)->post(route($route), ['name' => 'Strike Zone Bowling', $field => UploadedFile::fake()->image('picture.png')]);

        $response->assertSessionHasErrors([$field => 'Uploading pictures is turned off in the demo.']);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'logo' => ['branding.update', 'logo'],
        'cover photo' => ['front-page.update', 'cover'],
    ]);

    test('the venue\'s other settings can still be changed', function () {
        $response = $this->actingAs($this->admin)->post(route('branding.update'), ['name' => 'Renamed Bowl']);

        $response->assertSessionHasNoErrors();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('name', 'Renamed Bowl'));
    });
});
