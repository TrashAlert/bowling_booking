<?php

namespace App\Http\Controllers;

use App\Http\Requests\Staff\ReservationRequest;
use App\Http\Requests\SubmitBookingRequest;
use App\Models\BookingRequest;
use App\Services\BookingRequests;
use App\Services\LaneBoard;
use App\Services\OpeningHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReserveLaneController extends Controller
{
    // Where the thank-you page finds the request just sent, for one visit.
    private const SESSION_KEY = 'booking_request_id';

    /**
     * Show the form where a customer asks for a reservation. Staff call them
     * to confirm it.
     */
    public function create(LaneBoard $board, OpeningHours $hours): Response
    {
        return Inertia::render('reserve/lane', [
            'session' => $board->sessionRules(),
            'limits' => [
                'maxPartySize' => ReservationRequest::MAX_PARTY_SIZE,
                'maxDaysAhead' => config('bowling.reservation_max_days_ahead'),
            ],
            // A reservation must start and end within these. Null while no
            // hours are set, when any time can be picked.
            'openingHours' => $hours->isSet() ? $hours->week() : null,
        ]);
    }

    /**
     * Save the customer's request and thank them. Nothing is booked yet.
     */
    public function store(SubmitBookingRequest $request, BookingRequests $requests): RedirectResponse
    {
        [$contactFrom, $contactUntil] = $request->callWindow();

        $bookingRequest = $requests->submit(
            $request->string('name')->toString(),
            $request->string('phone')->toString(),
            $request->integer('party_size'),
            $request->integer('minutes'),
            $request->startsAt(),
            $contactFrom,
            $contactUntil,
            $request->input('notes'),
        );

        return to_route('booking-requests.thanks')->with(self::SESSION_KEY, $bookingRequest->id);
    }

    /**
     * Thank the customer for the request they have just sent. It is only
     * shown straight after sending; coming back later starts a new request.
     */
    public function thanks(Request $request): Response|RedirectResponse
    {
        $bookingRequest = BookingRequest::query()->find($request->session()->get(self::SESSION_KEY));

        if ($bookingRequest === null) {
            return to_route('reserve');
        }

        return Inertia::render('reserve/thanks', [
            'request' => [
                'name' => $bookingRequest->name,
                'phone' => $bookingRequest->phone,
                'partySize' => $bookingRequest->party_size,
                'minutes' => $bookingRequest->minutes,
                'startsAt' => $bookingRequest->starts_at->toIso8601String(),
                'contactFrom' => $bookingRequest->contact_from === null ? null : substr($bookingRequest->contact_from, 0, 5),
                'contactUntil' => $bookingRequest->contact_until === null ? null : substr($bookingRequest->contact_until, 0, 5),
            ],
        ]);
    }
}
