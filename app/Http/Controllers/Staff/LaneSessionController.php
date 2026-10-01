<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Models\Lane;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LaneSessionController extends Controller
{
    /**
     * End the session on a lane before its time is up. The waitlist is left
     * alone: staff call the next party themselves.
     */
    public function destroy(Lane $lane, BookingService $bookings): RedirectResponse
    {
        try {
            $booking = $bookings->endSessionOnLane($lane);

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name\'s session on lane :number was ended.', [
                'name' => $booking->customer->name,
                'number' => $lane->number,
            ])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.board'));
    }
}
