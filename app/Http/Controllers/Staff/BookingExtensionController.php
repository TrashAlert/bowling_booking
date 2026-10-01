<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ExtendSessionRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Carbon\CarbonInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BookingExtensionController extends Controller
{
    /**
     * Give a party that is still playing more time on its lanes.
     */
    public function store(ExtendSessionRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $minutes = $request->integer('minutes');

        try {
            $bookings->extend($booking, $minutes);

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name\'s session was extended by :time.', [
                'name' => $booking->customer->name,
                'time' => CarbonInterval::minutes($minutes)->cascade()->forHumans(),
            ])]);
        } catch (NoLaneAvailableException $exception) {
            throw ValidationException::withMessages([
                'minutes' => __('Lane :number is booked too soon after this session to extend it that long.', [
                    'number' => $exception->laneNumber,
                ]),
            ]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.board'));
    }
}
