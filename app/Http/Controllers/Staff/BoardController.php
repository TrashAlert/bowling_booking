<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\LaneBoard;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * Show the lane board staff use at the counter.
     */
    public function __invoke(LaneBoard $board): Response
    {
        return Inertia::render('staff/board', [
            'lanes' => $board->lanes(),
            'waitlist' => $board->waitlist(),
            'reservations' => $board->reservations(),
            'packages' => $board->packages(),
            'serverNow' => now()->toIso8601String(),
        ]);
    }
}
