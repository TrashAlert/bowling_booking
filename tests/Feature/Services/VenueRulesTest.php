<?php

use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Services\VenueRules;
use Illuminate\Support\Facades\Schema;

test('a saved rule is used from the moment it is saved', function () {
    app(VenueRules::class)->save('waitlist_deposit_cents', 1500);

    expect(config('bowling.waitlist_deposit_cents'))->toBe(1500);
    $this->assertDatabaseHas('settings', ['key' => 'bowling.waitlist_deposit_cents']);
});

test('a saved rule replaces the .env value each time the app starts', function () {
    Setting::create(['key' => 'bowling.waitlist_deposit_cents', 'value' => 2500]);

    (new AppServiceProvider($this->app))->boot();

    expect(config('bowling.waitlist_deposit_cents'))->toBe(2500);
});

test('a rule nobody has saved keeps its .env value', function () {
    (new AppServiceProvider($this->app))->boot();

    expect(config('bowling.waitlist_deposit_cents'))->toBe(1000);
});

test('only the rules offered in the settings can be saved', function () {
    expect(fn () => app(VenueRules::class)->save('version', '9.9.9'))
        ->toThrow(InvalidArgumentException::class, 'The rule [version] cannot be changed from the settings.');

    $this->assertDatabaseCount('settings', 0);
});

test('the app still starts before the settings table exists', function () {
    Schema::drop('settings');

    (new AppServiceProvider($this->app))->boot();

    expect(config('bowling.waitlist_deposit_cents'))->toBe(1000);
});
