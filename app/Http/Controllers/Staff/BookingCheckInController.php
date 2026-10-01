<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingCheckInController extends Controller
{
    /**
     * Check in a party that has arrived for its reservation.
     */
    public function store(Booking $booking, BookingService $bookings): RedirectResponse
    {
        try {
            $bookings->checkIn($booking);

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is checked in.', ['name' => $booking->customer->name])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('staff.board');
    }
}
