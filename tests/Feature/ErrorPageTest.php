<?php

use App\Services\Branding;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(Branding::class)->rename('Kingpin Bowl');
});

test('a mistyped ticket link shows the not-found page with the business name', function () {
    $response = $this->get('/waitlist/'.str_repeat('a', 40));

    $response->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404)
            ->where('name', 'Kingpin Bowl'));
});

test('an address that does not exist shows the not-found page with the business name', function () {
    $response = $this->get('/no-such-page');

    $response->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404)
            ->where('name', 'Kingpin Bowl'));
});

test('too many tries in a row show the too-many-tries page', function () {
    foreach (range(1, 5) as $try) {
        $this->post(route('waitlist.store'), []);
    }

    $response = $this->post(route('waitlist.store'), []);

    $response->assertTooManyRequests()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 429));
});

test('a fault shows the error page while debugging is off', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/test-fault', fn () => throw new RuntimeException('Broken'));

    $response = $this->get('/test-fault');

    $response->assertInternalServerError()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 500));
});

test('a fault shows Laravel\'s own page, with its details, while debugging is on', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/test-fault', fn () => throw new RuntimeException('Broken in a findable way'));

    $response = $this->get('/test-fault');

    $response->assertInternalServerError()->assertSee('Broken in a findable way');
});

test('a request that wants JSON gets Laravel\'s own answer', function () {
    $response = $this->getJson('/no-such-page');

    $response->assertNotFound()->assertJsonStructure(['message']);
});
