<?php

namespace App\Http\Controllers\Staff;

use App\Concerns\ProvidesLaneOptions;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ReservationRequest;
use App\Models\BookingRequest;
use App\Services\BookingRequests;
use App\Services\LaneAvailability;
use App\Services\LaneBoard;
use App\Services\ReservationSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingRequestController extends Controller
{
    use ProvidesLaneOptions;

    /**
     * Show the reservation requests customers have sent: the ones still to
     * deal with, and the ones already dealt with or missed.
     */
    public function index(Request $request, BookingRequests $requests, LaneBoard $board, LaneAvailability $availability): Response
    {
        return Inertia::render('staff/requests', [
            'waiting' => $requests->waiting(),
            'handled' => $requests->handled(),
            // For the reservation form that confirms a request.
            'session' => $board->sessionRules(),
            'limits' => [
                'maxPartySize' => ReservationRequest::MAX_PARTY_SIZE,
                'leadMinutes' => config('bowling.reservation_lead_minutes'),
                'maxDaysAhead' => config('bowling.reservation_max_days_ahead'),
                'searchLimit' => ReservationSchedule::SEARCH_LIMIT,
            ],
            'laneOptions' => $this->laneOptions($request, $availability),
        ]);
    }

    /**
     * Decline a request, with an optional reason kept for staff.
     */
    public function destroy(Request $request, BookingRequest $bookingRequest, BookingRequests $requests): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $requests->decline($bookingRequest, $validated['reason'] ?? null, $request->user());

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name\'s request was declined.', ['name' => $bookingRequest->name])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.requests.index'));
    }
}
