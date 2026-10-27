<?php

use App\Models\Lane;
use App\Models\WaitlistEntry;

test('nothing is reset while demo mode is off', function () {
    $lane = Lane::factory()->create();

    $this->artisan('demo:reset', ['--force' => true])
        ->expectsOutputToContain('Demo mode is off, so nothing was reset.')
        ->assertFailed();

    $this->assertModelExists($lane);
});

test('nothing is reset on a database whose name does not say it is the demo, even in demo mode', function () {
    config(['bowling.demo' => true]);
    $lane = Lane::factory()->create();

    $this->artisan('demo:reset', ['--force' => true])
        ->expectsOutputToContain('The database [bowling_test] is not a demo database')
        ->assertFailed();

    $this->assertModelExists($lane);
});

test('the demo is only kept busy on the demo', function (bool $demoMode) {
    config(['bowling.demo' => $demoMode]);

    $this->artisan('demo:liven')
        ->expectsOutputToContain('This only runs on the demo')
        ->assertFailed();

    expect(WaitlistEntry::query()->count())->toBe(0);
})->with([
    'demo mode off' => [false],
    'demo mode on, but not a demo database' => [true],
]);
