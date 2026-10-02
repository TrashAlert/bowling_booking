<?php

namespace App\Http\Controllers;

use App\Services\LaneAvailability;
use App\Services\LaneInventory;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the front page, with how many lanes are free right now so a
     * customer can decide between walking in and booking ahead.
     *
     * A lane counts as free when it is open and nothing is on it: no session,
     * no hold for a called party, no closure, and not the closed hour before
     * a reservation.
     */
    public function __invoke(LaneAvailability $availability, LaneInventory $inventory): Response
    {
        $now = now();

        return Inertia::render('welcome', [
            'lanes' => [
                'free' => $availability->freeLaneCount($now, $now->addMinute()),
                'total' => $inventory->count(),
            ],
        ]);
    }
}
