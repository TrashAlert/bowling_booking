<?php

namespace App\Http\Controllers;

use App\Services\LaneBoard;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistJoinController extends Controller
{
    /**
     * Show the page where a walk-in party will add itself to the waitlist.
     * It is a sample for now: the page saves nothing.
     */
    public function __invoke(LaneBoard $board): Response
    {
        return Inertia::render('waitlist/join', [
            'session' => $board->sessionRules(),
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
        ]);
    }
}
