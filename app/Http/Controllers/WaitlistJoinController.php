<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinWaitlistRequest;
use App\Services\LaneBoard;
use App\Services\WaitlistDeposits;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistJoinController extends Controller
{
    /**
     * Show the page where a party adds itself to the waitlist.
     */
    public function create(LaneBoard $board): Response
    {
        return Inertia::render('waitlist/join', [
            'session' => $board->sessionRules(),
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
            'depositCents' => config('bowling.waitlist_deposit_cents'),
        ]);
    }

    /**
     * Take the party's details and send it to pay the deposit. It joins the
     * line once that is paid.
     */
    public function store(JoinWaitlistRequest $request, WaitlistDeposits $deposits): RedirectResponse
    {
        $deposit = $deposits->start(
            $request->string('name')->toString(),
            $request->string('phone')->toString(),
            $request->integer('minutes'),
            $request->integer('party_size'),
        );

        return to_route('waitlist.deposit.show', ['deposit' => $deposit->token]);
    }
}
