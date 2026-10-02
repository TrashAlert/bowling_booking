<?php

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Services\WaitlistTicket;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistEntryController extends Controller
{
    /**
     * Show a party its own place in line. The page is reached by the secret
     * link the party was given, and refreshes itself.
     */
    public function show(WaitlistEntry $entry, WaitlistTicket $ticket): Response
    {
        return Inertia::render('waitlist/show', [
            'ticket' => $ticket->for($entry),
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
