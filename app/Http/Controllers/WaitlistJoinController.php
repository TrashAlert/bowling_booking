<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Http\Requests\JoinWaitlistRequest;
use App\Services\LaneAvailability;
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
    public function create(LaneBoard $board, WaitlistEstimate $estimate, OpeningHours $hours, LaneAvailability $availability): Response
    {
        return Inertia::render('waitlist/join', [
            'session' => $board->sessionRules(),
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
            'depositCents' => config('bowling.waitlist_deposit_cents'),
            // Joining is only possible while the venue is open.
            'opening' => $hours->status(),
            // The likely wait for each session length; the page refreshes it.
            'waitMinutes' => fn () => $estimate->forNewParty(),
            // False while every lane is out of order: nobody can join then.
            'lanesOpen' => fn () => $availability->hasOpenLane(),
        ]);
    }

    /**
     * Take the party's details. While a lane is free for it the party is put
     * in line at once; otherwise it is sent to pay the deposit and joins the
     * line once that is paid. It is turned away when no lane can be found for
     * its session, since it would pay to wait in a line that can't move:
     * either every lane is closed, or none is free for that long.
     */
    public function store(JoinWaitlistRequest $request, WaitlistDeposits $deposits, LaneAvailability $availability): RedirectResponse
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
        } catch (NoLaneAvailableException) {
            throw ValidationException::withMessages($availability->hasOpenLane()
                ? ['minutes' => __('We can\'t find a lane for that session right now. Try a shorter one, or ask our staff at the counter.')]
                : ['closed' => __('All our lanes are closed for maintenance right now. Please check back later, or ask our staff at the counter.')]);
        }

        return $deposit->entry === null
            ? to_route('waitlist.deposit.show', ['deposit' => $deposit->token])
            : to_route('waitlist.show', ['entry' => $deposit->entry->token]);
    }
}
