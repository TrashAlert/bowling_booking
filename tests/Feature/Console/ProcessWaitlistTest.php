<?php

use App\Models\Lane;
use App\Models\WaitlistEntry;
use App\Services\WaitlistPush;

use function Pest\Laravel\mock;

test('the scheduled run notifies the phones of the parties it calls', function () {
    Lane::factory()->create();
    $entry = joinWaitlist();
    joinWaitlist(name: 'No lane for this one yet');

    mock(WaitlistPush::class)->shouldReceive('notifyCalled')->once()
        ->withArgs(fn (array $called) => array_map(fn (WaitlistEntry $party) => $party->id, $called) === [$entry->id]);

    $this->artisan('waitlist:process')->assertSuccessful();
});
