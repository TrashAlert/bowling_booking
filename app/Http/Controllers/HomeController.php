<?php

namespace App\Http\Controllers;

use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use App\Services\LaneAvailability;
use App\Services\LaneInventory;
use App\Services\OpeningHours;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistEstimate;
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
     * a reservation. The lanes that parties already in the waitlist are about
     * to be called to are taken off, so the number is what is left for
     * someone arriving now. When every lane is out of order the page says
     * that instead of a count.
     */
    public function __invoke(WaitlistEstimate $estimate, LaneInventory $inventory, OpeningHours $hours, LaneAvailability $availability, WaitlistDeposits $deposits): Response
    {
        return Inertia::render('welcome', [
            'lanes' => [
                'free' => $estimate->lanesFreeNow(),
                'total' => $inventory->count(),
                'waiting' => WaitlistEntry::query()->whereIn('status', WaitlistStatus::inLine())->count(),
            ],
            // False while every lane is out of order.
            'lanesOpen' => $availability->hasOpenLane(),
            // False while the deposit for joining online is turned off.
            'depositAsked' => $deposits->depositCents() > 0,
            'opening' => $hours->status(),
        ]);
    }
}
