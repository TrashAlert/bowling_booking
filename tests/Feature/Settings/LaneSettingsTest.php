<?php

use App\Models\Lane;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('lanes.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the lane settings', function (string $method, string $route) {
    Lane::factory()->create(['number' => 1]);

    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['count' => 5]);

    $response->assertForbidden();
    $this->assertDatabaseCount('lanes', 1);
})->with([
    'open the page' => ['get', 'lanes.edit'],
    'change the count' => ['put', 'lanes.update'],
]);

test('admins see how many lanes there are', function () {
    Lane::factory()->create(['number' => 1]);
    Lane::factory()->create(['number' => 2]);

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('lanes.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/lanes')
        ->where('laneCount', 2)
        ->where('maxLanes', 100));
});

test('admins can change how many lanes there are', function () {
    Lane::factory()->create(['number' => 1]);

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('lanes.update'), ['count' => 3]);

    $response->assertRedirect(route('lanes.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The venue now has 3 lanes.']);
    expect(Lane::query()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3]);
});

test('the number of lanes must be a whole number from 1 to 100', function (mixed $count, string $message) {
    Lane::factory()->create(['number' => 1]);

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('lanes.update'), ['count' => $count]);

    $response->assertSessionHasErrors(['count' => $message]);
    $this->assertDatabaseCount('lanes', 1);
})->with([
    'missing' => [null, 'The number of lanes field is required.'],
    'not a number' => ['many', 'The number of lanes field must be an integer.'],
    'zero' => [0, 'The number of lanes field must be at least 1.'],
    'too many' => [101, 'The number of lanes field must not be greater than 100.'],
]);

test('lowering the count is refused while a lane to be removed is booked', function () {
    $this->travelTo('2026-10-01 18:00:00');
    Lane::factory()->create(['number' => 1]);
    Lane::factory()->create(['number' => 2]);
    bookReservation(now());
    bookReservation(now());

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('lanes.update'), ['count' => 1]);

    $response->assertSessionHasErrors([
        'count' => "Lane 2 is in use or has bookings coming up, so it can't be removed.",
    ]);
    expect(Lane::query()->count())->toBe(2);
});
