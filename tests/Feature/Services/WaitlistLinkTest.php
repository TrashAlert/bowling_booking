<?php

use App\Services\WaitlistLink;
use Illuminate\Http\Request;

test('the link to a party\'s page is on the public address when one is set', function () {
    config(['bowling.public_url' => 'https://bowl.example.com/']);
    $entry = joinWaitlist();

    $url = app(WaitlistLink::class)->url($entry);

    expect($url)->toBe("https://bowl.example.com/waitlist/{$entry->token}");
});

test('the link is on the address the screen was opened on while no public address is set', function () {
    $entry = joinWaitlist();
    // The lane board, opened on the counter computer over the venue's network.
    $this->app->instance('request', Request::create('http://192.168.1.5:8000/staff/board'));

    $url = app(WaitlistLink::class)->url($entry);

    expect($url)->toBe("http://192.168.1.5:8000/waitlist/{$entry->token}");
});
