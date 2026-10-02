<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\WaitlistEntry;
use App\Services\WaitlistPush;
use Illuminate\Http\RedirectResponse;

class WaitlistPushSubscriptionController extends Controller
{
    /**
     * A party has turned notifications on from its own page: remember where
     * to notify its phone when it is called.
     */
    public function store(StorePushSubscriptionRequest $request, WaitlistEntry $entry, WaitlistPush $push): RedirectResponse
    {
        $push->subscribe(
            $entry,
            $request->string('endpoint')->toString(),
            $request->string('keys.p256dh')->toString(),
            $request->string('keys.auth')->toString(),
        );

        return to_route('waitlist.show', ['entry' => $entry->token]);
    }
}
