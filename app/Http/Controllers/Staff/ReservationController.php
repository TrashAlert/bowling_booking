<?php

namespace App\Http\Controllers\Staff;

use App\Concerns\ProvidesLaneOptions;
use App\Enums\BookingSource;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ReservationRequest;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Services\BookingRequests;
use App\Services\BookingService;
use App\Services\LaneAvailability;
use App\Services\LaneBoard;
use App\Services\ReservationSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    use ProvidesLaneOptions;

    /**
     * Show one day's reservations. The day is a calendar date in the time
     * zone of the device asking, since the venue has no time zone set yet.
     * With a search term, the reservations matching it on any day are shown
     * in place of the day's.
     *
     * The lanes for the reservation form are only worked out when the form
     * asks for them, for the start time and length it has at that moment.
     */
    public function index(Request $request, ReservationSchedule $schedule, LaneAvailability $availability, LaneBoard $board): Response
    {
        $query = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'tz' => ['nullable', 'timezone'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $timeZone = $query['tz'] ?? 'UTC';
        $date = $query['date'] ?? CarbonImmutable::now($timeZone)->toDateString();
        $dayStart = CarbonImmutable::parse($date, $timeZone)->startOfDay();
        $search = $query['search'] ?? null;

        return Inertia::render('staff/reservations', [
            'date' => $date,
            'search' => $search,
            'reservations' => $search === null
                // Midnight to midnight on that device's clock; 23 or 25 hours on a clock-change day.
                ? $schedule->between($dayStart, $dayStart->addDay())
                : $schedule->search($search),
            'session' => $board->sessionRules(),
            'limits' => [
                'maxPartySize' => ReservationRequest::MAX_PARTY_SIZE,
                'leadMinutes' => config('bowling.reservation_lead_minutes'),
                'maxDaysAhead' => config('bowling.reservation_max_days_ahead'),
                'searchLimit' => ReservationSchedule::SEARCH_LIMIT,
            ],
            'laneOptions' => $this->laneOptions($request, $availability),
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    /**
     * Make a reservation.
     */
    public function store(ReservationRequest $request, BookingService $bookings, BookingRequests $requests): RedirectResponse
    {
        try {
            $booking = $request->filled('booking_request_id')
                // Confirming a customer's request: make the reservation and mark the request done.
                ? $requests->confirm(
                    BookingRequest::query()->findOrFail($request->integer('booking_request_id')),
                    $request->string('name')->toString(),
                    $request->string('phone')->toString(),
                    $request->lanes(),
                    $request->integer('minutes'),
                    $request->integer('party_size'),
                    $request->startsAt(),
                    $request->input('notes'),
                    $request->user(),
                )
                : $bookings->reserveForNewCustomer(
                    $request->string('name')->toString(),
                    $request->string('phone')->toString(),
                    $request->lanes(),
                    $request->integer('minutes'),
                    $request->integer('party_size'),
                    $request->startsAt(),
                    $request->input('notes'),
                );
        } catch (NoLaneAvailableException $exception) {
            $this->failLanes($exception);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back(fallback: route('staff.requests.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is booked.', ['name' => $booking->customer->name])]);

        return back(fallback: route('staff.reservations.index'));
    }

    /**
     * Change a reservation's details, time or lanes.
     */
    public function update(ReservationRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $this->ensureReservation($booking);

        try {
            $bookings->reschedule(
                $booking,
                $request->string('name')->toString(),
                $request->string('phone')->toString(),
                $request->lanes(),
                $request->integer('minutes'),
                $request->integer('party_size'),
                $request->startsAt(),
                $request->input('notes'),
            );

            Inertia::flash('toast', ['type' => 'success', 'message' => __('The reservation was updated.')]);
        } catch (NoLaneAvailableException $exception) {
            $this->failLanes($exception);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.reservations.index'));
    }

    /**
     * Cancel a reservation and free its lanes.
     */
    public function destroy(Booking $booking, BookingService $bookings): RedirectResponse
    {
        $this->ensureReservation($booking);

        try {
            $bookings->cancel($booking);

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name\'s reservation was cancelled.', ['name' => $booking->customer->name])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.reservations.index'));
    }

    /**
     * Walk-in sessions are bookings too, but they are run from the waitlist.
     */
    private function ensureReservation(Booking $booking): void
    {
        abort_if($booking->source === BookingSource::WalkIn, 404);
    }

    /**
     * Show a lane that was taken in the meantime as an error on the lanes field.
     *
     * @throws ValidationException
     */
    private function failLanes(NoLaneAvailableException $exception): never
    {
        throw ValidationException::withMessages([
            'lane_ids' => __('Lane :number is not free for that time. Pick another lane or time.', [
                'number' => $exception->laneNumber,
            ]),
        ]);
    }
}
