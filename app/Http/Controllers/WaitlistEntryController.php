<?php

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use App\Services\WaitlistPlaces;
use App\Services\WaitlistPush;
use App\Services\WaitlistService;
use App\Services\WaitlistTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistEntryController extends Controller
{
    /**
     * Show a party its own place in line. The page is reached by the secret
     * link the party was given, and refreshes itself. The phone remembers it,
     * so the front page can lead back to it. A day after the visit ends the
     * page is gone, with nothing on it about the party.
     */
    public function show(Request $request, WaitlistEntry $entry, WaitlistTicket $ticket, WaitlistPush $push, WaitlistPlaces $places): Response
    {
        abort_if($ticket->expired($entry), 410);

        $places->remember($request, $entry);

        return Inertia::render('waitlist/show', [
            'ticket' => $ticket->for($entry),
            // The public half of the key pair, which the phone needs to turn
            // notifications on. Null while notifications aren't set up.
            'pushKey' => $push->isConfigured() ? config('services.web_push.public_key') : null,
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    /**
     * The party takes itself out of the line. Nothing changes if it is no
     * longer in it.
     */
    public function destroy(WaitlistEntry $entry, WaitlistService $waitlist): RedirectResponse
    {
        $waitlist->leave($entry);

        return to_route('waitlist.show', ['entry' => $entry->token]);
    }
}
