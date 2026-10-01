<?php

namespace App\Http\Controllers\Staff;

use App\Enums\BookingSource;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\MoveLanesRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ReservationLaneController extends Controller
{
    /**
     * Put a reservation on other lanes, keeping its time. Staff do this when
     * one of its lanes has been closed, before the group can check in.
     */
    public function update(MoveLanesRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        // Walk-in sessions are bookings too, but they are run from the waitlist.
        abort_if($booking->source === BookingSource::WalkIn, 404);

        $lanes = $request->lanes();

        try {
            $bookings->moveLanes($booking, $lanes);

            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
                ':name\'s reservation is now on lane :lanes.|:name\'s reservation is now on lanes :lanes.',
                $lanes->count(),
                [
                    'name' => $booking->customer->name,
                    'lanes' => Arr::join($lanes->pluck('number')->all(), ', ', ' and '),
                ],
            )]);
        } catch (NoLaneAvailableException $exception) {
            throw ValidationException::withMessages([
                'lane_ids' => __('Lane :number is not free for that time. Pick another lane.', [
                    'number' => $exception->laneNumber,
                ]),
            ]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.reservations.index'));
    }
}
