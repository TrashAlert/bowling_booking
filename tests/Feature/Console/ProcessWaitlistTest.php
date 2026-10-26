<?php

use App\Models\Lane;
use App\Models\WaitlistEntry;
use App\Services\WaitlistPush;

use function Pest\Laravel\mock;

test('the scheduled run notifies the phones of the parties it calls', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    joinWaitlist(name: 'No lane for this one yet');

    $push = mock(WaitlistPush::class);
    $push->shouldReceive('notifyCalled')->once()
        ->withArgs(fn (array $called) => array_map(fn (WaitlistEntry $party) => $party->id, $called) === [$entry->id]);
    $push->shouldReceive('notifyGetReady')->andReturn(0);

    $this->artisan('waitlist:process')->assertSuccessful();
});

test('the scheduled run tells parties to get ready after making its calls', function () {
    Lane::factory()->create();
    joinWaitlist();

    $push = mock(WaitlistPush::class);
    $push->shouldReceive('notifyCalled')->once()->ordered();
    $push->shouldReceive('notifyGetReady')->once()->ordered()->andReturn(1);

    $this->artisan('waitlist:process')
        ->expectsOutputToContain('told 1 party(s) to get ready')
        ->assertSuccessful();
});
