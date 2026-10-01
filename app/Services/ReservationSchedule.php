<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\LaneAllocation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only lists of reservations for the staff Reservations page. Every time
 * is an ISO 8601 string in UTC; the browser converts it for display.
 *
 * @phpstan-type ReservationRow array{
 *     id: int,
 *     customerName: string,
 *     phone: string|null,
 *     partySize: int,
 *     minutes: int,
 *     status: string,
 *     startsAt: string,
 *     endsAt: string,
 *     checkInOpensAt: string,
 *     lanes: list<array{id: int, number: int}>,
 *     notes: string|null,
 *     closedLaneNumbers: list<int>,
 * }
 */
class ReservationSchedule
{
    // The most reservations a search lists: the ones made most recently.
    public const SEARCH_LIMIT = 50;

    /**
     * Phone and online reservations whose session starts from $from up to (not
     * including) $to, earliest first. Cancelled ones and no-shows are included,
     * so the day's list shows what happened to every reservation.
     *
     * @return list<ReservationRow>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        // A date is written to a query as its own clock time, so a window
        // given in a local zone has to be turned into UTC first.
        $from = $from->toImmutable()->utc();
        $to = $to->toImmutable()->utc();

        return $this->reservations()
            ->whereHas('allocations', function (Builder $query) use ($from, $to) {
                $query->where('starts_at', '>=', $from)->where('starts_at', '<', $to);
            })
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Booking $booking) => $booking->allocations->min('starts_at')->getTimestamp())
            ->values()
            ->map(fn (Booking $booking) => $this->row($booking))
            ->all();
    }

    /**
     * Phone and online reservations on any day whose customer's name contains
     * $term, whatever the case. A term that is written like a phone number is
     * also looked for in the phone numbers, ignoring spaces and punctuation
     * on both sides.
     *
     * Reservations still to come are listed first, soonest first, then past
     * ones, latest first.
     *
     * @return list<ReservationRow>
     */
    public function search(string $term): array
    {
        $digits = preg_match('/^[\d\s+().-]+$/', $term) === 1 ? preg_replace('/\D/', '', $term) : '';
        $now = now();

        return $this->reservations()
            ->has('allocations')
            ->whereHas('customer', function (Builder $query) use ($term, $digits) {
                $query->where(function (Builder $query) use ($term, $digits) {
                    // % and _ are escaped so they are looked for as typed.
                    $query->whereLike('name', '%'.addcslashes($term, '\\%_').'%');

                    if ($digits !== '') {
                        $query->orWhereRaw("regexp_replace(phone, '\\D', '', 'g') like ?", ["%{$digits}%"]);
                    }
                });
            })
            ->latest('id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->sortBy(function (Booking $booking) use ($now) {
                $startsAt = $booking->allocations->min('starts_at')->getTimestamp();
                $isOver = $booking->allocations->max('ends_at') <= $now;

                // Compared left to right: what is still to come, then by time.
                return [$isOver, $isOver ? -$startsAt : $startsAt];
            })
            ->values()
            ->map(fn (Booking $booking) => $this->row($booking))
            ->all();
    }

    /**
     * Reservations made by phone or online, with what a row needs loaded.
     * Walk-in sessions are bookings too, but they are run from the waitlist.
     *
     * @return Builder<Booking>
     */
    private function reservations(): Builder
    {
        return Booking::query()
            ->whereIn('source', [BookingSource::Online->value, BookingSource::Phone->value])
            ->with(['customer', 'allocations.lane']);
    }

    /**
     * One reservation as the Reservations page lists it.
     *
     * @return ReservationRow
     */
    private function row(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'customerName' => $booking->customer->name,
            'phone' => $booking->customer->phone,
            'partySize' => $booking->party_size,
            'minutes' => $booking->minutes,
            'status' => $booking->status->value,
            'startsAt' => $booking->allocations->min('starts_at')->toIso8601String(),
            'endsAt' => $booking->allocations->max('ends_at')->toIso8601String(),
            // Check-in is refused before this moment.
            'checkInOpensAt' => $booking->allocations->min('starts_at')
                ->subMinutes(config('bowling.check_in_opens_minutes'))
                ->toIso8601String(),
            'lanes' => $booking->allocations
                ->sortBy('lane.number')
                ->map(fn (LaneAllocation $allocation) => [
                    'id' => $allocation->lane_id,
                    'number' => $allocation->lane->number,
                ])
                ->values()
                ->all(),
            'notes' => $booking->notes,
            // Lanes marked out of order under a reservation still to check in.
            'closedLaneNumbers' => $booking->status === BookingStatus::Confirmed
                ? $booking->allocations
                    ->filter(fn (LaneAllocation $allocation) => $allocation->lane->needsMovingAt($allocation->starts_at))
                    ->pluck('lane.number')
                    ->sort()
                    ->values()
                    ->all()
                : [],
        ];
    }
}
