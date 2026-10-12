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
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistJoinController extends Controller
{
    // The cookie a phone keeps of the online joins it has made, so it can be
    // held to a few groups in the line at once. Laravel encrypts it, so it
    // can't be edited; clearing it lets a phone start over, which the limit
    // on joins per minute is there to slow down.
    private const DEVICE_COOKIE = 'waitlist_groups';

    // How long the phone keeps that cookie, and how many joins it lists.
    private const DEVICE_COOKIE_MINUTES = 60 * 24;

    private const DEVICE_COOKIE_SIZE = 20;

    /**
     * Show the page where a party adds itself to the waitlist.
     */
    public function create(Request $request, LaneBoard $board, WaitlistEstimate $estimate, OpeningHours $hours, LaneAvailability $availability, WaitlistDeposits $deposits): Response
    {
        return Inertia::render('waitlist/join', [
            'session' => $board->sessionRules(),
            'checkInMinutes' => config('bowling.waitlist_checkin_minutes'),
            // Zero while the deposit is turned off in the settings.
            'depositCents' => $deposits->depositCents(),
            // Joining is only possible while the venue is open.
            'opening' => $hours->status(),
            // The likely wait for each session length; the page refreshes it.
            'waitMinutes' => fn () => $estimate->forNewParty(),
            // False while every lane is out of order: nobody can join then.
            'lanesOpen' => fn () => $availability->hasOpenLane(),
            // How many groups this phone may have in the line at once, and
            // whether it already has that many.
            'deviceLimit' => fn () => [
                'max' => config('bowling.waitlist_max_per_device'),
                'reached' => $deposits->deviceLimitReached($this->joinsFromThisDevice($request)),
            ],
        ]);
    }

    /**
     * Take the party's details. While a lane is free for it the party is put
     * in line at once; otherwise it is sent to pay the deposit and joins the
     * line once that is paid. It is turned away when no lane can be found for
     * its session, since it would pay to wait in a line that can't move:
     * either every lane is closed, or none is free for that long. A phone
     * that already has as many groups in the line as one phone may is turned
     * away too.
     */
    public function store(JoinWaitlistRequest $request, WaitlistDeposits $deposits, LaneAvailability $availability): RedirectResponse
    {
        $joins = $this->joinsFromThisDevice($request);

        if ($deposits->deviceLimitReached($joins)) {
            throw ValidationException::withMessages(['limit' => trans_choice(
                'This phone already has :count group in the line. Leave it first, or ask our staff at the counter to add your group.|This phone already has :count groups in the line. Leave one of them first, or ask our staff at the counter to add your group.',
                config('bowling.waitlist_max_per_device'),
            )]);
        }

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

        $response = $deposit->entry === null
            ? to_route('waitlist.deposit.show', ['deposit' => $deposit->token])
            : to_route('waitlist.show', ['entry' => $deposit->entry->token]);

        return $response->withCookie(cookie(
            self::DEVICE_COOKIE,
            json_encode(array_slice([...$joins, $deposit->id], -self::DEVICE_COOKIE_SIZE)),
            self::DEVICE_COOKIE_MINUTES,
        ));
    }

    /**
     * The online joins this phone has made, as the ids kept in its cookie.
     *
     * @return list<int>
     */
    private function joinsFromThisDevice(Request $request): array
    {
        $ids = json_decode((string) $request->cookie(self::DEVICE_COOKIE), true);

        return is_array($ids) ? array_values(array_filter($ids, is_int(...))) : [];
    }
}
