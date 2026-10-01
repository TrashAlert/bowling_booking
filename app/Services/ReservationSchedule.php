<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\LaneAllocation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only lists of reservations for the staff Reservations page. Every time
 * is an ISO 8601 string in UTC; the browser converts it for display.
 */
class ReservationSchedule
{
    /**
     * Phone and online reservations whose session starts from $from up to (not
     * including) $to, earliest first. Cancelled ones and no-shows are included,
     * so the day's list shows what happened to every reservation.
     *
     * @return list<array{
     *     id: int,
     *     customerName: string,
     *     phone: string|null,
     *     partySize: int,
     *     minutes: int,
     *     status: string,
     *     startsAt: string,
     *     endsAt: string,
     *     lanes: list<array{id: int, number: int}>,
     *     notes: string|null,
     * }>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        // A date is written to a query as its own clock time, so a window
        // given in a local zone has to be turned into UTC first.
        $from = $from->toImmutable()->utc();
        $to = $to->toImmutable()->utc();

        return Booking::query()
            ->whereIn('source', [BookingSource::Online->value, BookingSource::Phone->value])
            ->whereHas('allocations', function (Builder $query) use ($from, $to) {
                $query->where('starts_at', '>=', $from)->where('starts_at', '<', $to);
            })
            ->with(['customer', 'allocations.lane'])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Booking $booking) => $booking->allocations->min('starts_at')->getTimestamp())
            ->values()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'customerName' => $booking->customer->name,
                'phone' => $booking->customer->phone,
                'partySize' => $booking->party_size,
                'minutes' => $booking->minutes,
                'status' => $booking->status->value,
                'startsAt' => $booking->allocations->min('starts_at')->toIso8601String(),
                'endsAt' => $booking->allocations->max('ends_at')->toIso8601String(),
                'lanes' => $booking->allocations
                    ->sortBy('lane.number')
                    ->map(fn (LaneAllocation $allocation) => [
                        'id' => $allocation->lane_id,
                        'number' => $allocation->lane->number,
                    ])
                    ->values()
                    ->all(),
                'notes' => $booking->notes,
            ])
            ->all();
    }
}
