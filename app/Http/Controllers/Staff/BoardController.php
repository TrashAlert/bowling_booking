<?php

namespace App\Http\Controllers\Staff;

use App\Concerns\ProvidesLaneOptions;
use App\Http\Controllers\Controller;
use App\Services\LaneAvailability;
use App\Services\LaneBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    use ProvidesLaneOptions;

    /**
     * Show the lane board staff use at the counter.
     */
    public function __invoke(Request $request, LaneBoard $board, LaneAvailability $availability): Response
    {
        return Inertia::render('staff/board', [
            'lanes' => $board->lanes(),
            'waitlist' => $board->waitlist(),
            'reservations' => $board->reservations(),
            'session' => $board->sessionRules(),
            'closureOptions' => [
                'minutes' => config('bowling.closure_minutes'),
                'repairDays' => config('bowling.repair_days'),
            ],
            // For moving a reservation off a closed lane; only loaded on request.
            'laneOptions' => $this->laneOptions($request, $availability),
            'serverNow' => now()->toIso8601String(),
        ]);
    }
}
