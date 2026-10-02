<?php

namespace App\Http\Controllers;

use App\Http\Requests\Staff\ReservationRequest;
use App\Services\LaneBoard;
use Inertia\Inertia;
use Inertia\Response;

class ReserveLaneController extends Controller
{
    /**
     * Show the page where a customer will reserve lanes for a date and time.
     * It is a sample for now: the page saves nothing.
     */
    public function __invoke(LaneBoard $board): Response
    {
        return Inertia::render('reserve/lane', [
            'session' => $board->sessionRules(),
            'limits' => [
                'maxPartySize' => ReservationRequest::MAX_PARTY_SIZE,
                'maxDaysAhead' => config('bowling.reservation_max_days_ahead'),
                'checkInOpensMinutes' => config('bowling.check_in_opens_minutes'),
                'noShowGraceMinutes' => config('bowling.no_show_grace_minutes'),
            ],
        ]);
    }
}
