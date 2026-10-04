<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Http\Requests\JoinWaitlistRequest;
use App\Services\LaneBoard;
use App\Services\OpeningHours;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistEstimate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistJoinController extends Controller
{
    /**
     * Show the page where a party adds itself to the waitlist.
     */
    public function create(LaneBoard $board, WaitlistEstimate $estimate, OpeningHours $hours): Response
    {
        return Inertia::render('waitlist/join', [
            'session' => $board->sessionRules(),
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
            'depositCents' => config('bowling.waitlist_deposit_cents'),
            // Joining is only possible while the venue is open.
            'opening' => $hours->status(),
            // The likely wait for each session length; the page refreshes it.
            'waitMinutes' => fn () => $estimate->forNewParty(),
        ]);
    }

    /**
     * Take the party's details. While a lane is free for it the party is put
     * in line at once; otherwise it is sent to pay the deposit and joins the
     * line once that is paid.
     */
    public function store(JoinWaitlistRequest $request, WaitlistDeposits $deposits): RedirectResponse
    {
        try {
            $deposit = $deposits->start(
                $request->string('name')->toString(),
                $request->string('phone')->toString(),
                $request->integer('minutes'),
                $request->integer('party_size'),
            );
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['closed' => $exception->getMessage()]);
        }

        return $deposit->entry === null
            ? to_route('waitlist.deposit.show', ['deposit' => $deposit->token])
            : to_route('waitlist.show', ['entry' => $deposit->entry->token]);
    }
}
