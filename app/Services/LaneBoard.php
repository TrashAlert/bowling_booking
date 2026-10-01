<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\LaneCardState;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\WaitlistEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Read-only data for the staff lane board. Every time is an ISO 8601 string
 * in UTC; the browser converts it for display.
 */
class LaneBoard
{
    // How far ahead the board looks for "next up" and upcoming reservations.
    private const LOOKAHEAD_HOURS = 24;

    /**
     * One card per lane: what it is doing now and what comes next.
     *
     * @return list<array{
     *     id: int,
     *     number: int,
     *     hasBumpers: bool,
     *     isOpen: bool,
     *     state: string,
     *     closure: array{reason: string|null, until: string|null}|null,
     *     reservationsAhead: int,
     *     current: array{bookingId: int|null, customerName: string|null, partySize: int|null, startsAt: string, endsAt: string, heldUntil: string|null, note: string|null, isRunning: bool, extendableMinutes: int|null, partyLaneNumbers: list<int>, closureReason: string|null}|null,
     *     next: array{startsAt: string, customerName: string|null, note: string|null, isClosure: bool, closureReason: string|null}|null,
     * }>
     */
    public function lanes(): array
    {
        $now = now();

        $lanes = Lane::query()
            ->orderBy('number')
            ->with(['allocations' => function (Relation $query) use ($now) {
                $this->upcoming($query->getQuery(), $now)
                    ->orderBy('starts_at')
                    ->with(['booking.customer', 'closedForBooking.customer']);
            }])
            ->get();

        $currentOn = fn (Lane $lane) => $lane->allocations->first(fn (LaneAllocation $allocation) => $allocation->starts_at <= $now);
        $nextOn = fn (Lane $lane) => $lane->allocations->first(fn (LaneAllocation $allocation) => $allocation->starts_at > $now);

        $reservationsAhead = $this->reservationsAhead($now);

        // A party on several lanes can only be extended as far as the lane
        // that is booked again soonest, so find that moment per booking.
        $bookedAgainAt = [];
        $laneNumbers = [];

        foreach ($lanes as $lane) {
            $bookingId = $currentOn($lane)?->booking_id;
            $following = $nextOn($lane)?->starts_at->getTimestamp();

            if ($bookingId !== null) {
                $laneNumbers[$bookingId][] = $lane->number;
            }

            if ($bookingId !== null && $following !== null) {
                $bookedAgainAt[$bookingId] = min($bookedAgainAt[$bookingId] ?? $following, $following);
            }
        }

        return $lanes
            ->map(function (Lane $lane) use ($currentOn, $nextOn, $bookedAgainAt, $laneNumbers, $reservationsAhead) {
                $current = $currentOn($lane);
                $next = $nextOn($lane);

                return [
                    'id' => $lane->id,
                    'number' => $lane->number,
                    'hasBumpers' => $lane->has_bumpers,
                    'isOpen' => $lane->isOpen(),
                    'state' => $this->state($lane, $current)->value,
                    // Why and until when an out-of-order lane is closed.
                    'closure' => $lane->isOpen() ? null : [
                        'reason' => $lane->closed_reason?->value,
                        'until' => $lane->closed_until?->toIso8601String(),
                    ],
                    'reservationsAhead' => $reservationsAhead[$lane->id] ?? 0,
                    'current' => $current ? [
                        'bookingId' => $current->booking_id,
                        'customerName' => $this->party($current)?->customer->name,
                        'partySize' => $this->party($current)?->party_size,
                        'startsAt' => $current->starts_at->toIso8601String(),
                        'endsAt' => $current->ends_at->toIso8601String(),
                        'heldUntil' => $current->held_until?->toIso8601String(),
                        'note' => $current->note,
                        'isRunning' => $this->isRunningSession($current),
                        'extendableMinutes' => $this->isRunningSession($current)
                            ? $this->roomToExtend($current, $bookedAgainAt[$current->booking_id] ?? null)
                            : null,
                        'partyLaneNumbers' => $laneNumbers[$current->booking_id] ?? [],
                        'closureReason' => $current->closure_reason?->value,
                    ] : null,
                    'next' => $next ? [
                        'startsAt' => $next->starts_at->toIso8601String(),
                        'customerName' => $this->party($next)?->customer->name,
                        'note' => $next->note,
                        'isClosure' => $next->closed_for_booking_id !== null,
                        'closureReason' => $next->closure_reason?->value,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * Everyone in line, first come first served. The entry's secret token is
     * deliberately left out.
     *
     * @return list<array{
     *     id: int,
     *     position: int,
     *     customerName: string,
     *     partySize: int,
     *     minutes: int,
     *     status: string,
     *     joinedAt: string,
     *     calledAt: string|null,
     *     checkInBy: string|null,
     *     laneNumbers: list<int>,
     * }>
     */
    public function waitlist(): array
    {
        return WaitlistEntry::query()
            ->inLine()
            ->with(['customer', 'booking.allocations.lane'])
            ->get()
            ->values()
            ->map(function (WaitlistEntry $entry, int $index) {
                $held = $entry->booking?->allocations->where('status', AllocationStatus::Held) ?? collect();

                return [
                    'id' => $entry->id,
                    'position' => $index + 1,
                    'customerName' => $entry->customer->name,
                    'partySize' => $entry->party_size,
                    'minutes' => $entry->minutes,
                    'status' => $entry->status->value,
                    'joinedAt' => $entry->created_at->toIso8601String(),
                    'calledAt' => $entry->called_at?->toIso8601String(),
                    'checkInBy' => $held->min('held_until')?->toIso8601String(),
                    'laneNumbers' => $held->pluck('lane.number')->sort()->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * Phone and online reservations that are under way or start soon,
     * earliest first.
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
     *     checkInOpensAt: string,
     *     laneNumbers: list<int>,
     *     lanes: list<array{id: int, number: int}>,
     *     closedLaneNumbers: list<int>,
     * }>
     */
    public function reservations(): array
    {
        $now = now();

        return Booking::query()
            ->whereIn('source', [BookingSource::Online->value, BookingSource::Phone->value])
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->whereHas('allocations', fn (Builder $query) => $this->upcoming($query, $now))
            ->with(['customer', 'allocations' => function (Relation $query) use ($now) {
                $this->upcoming($query->getQuery(), $now)->with('lane');
            }])
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
                // Check-in is refused before this moment.
                'checkInOpensAt' => $booking->allocations->min('starts_at')
                    ->subMinutes(config('bowling.check_in_opens_minutes'))
                    ->toIso8601String(),
                'laneNumbers' => $booking->allocations->pluck('lane.number')->sort()->values()->all(),
                'lanes' => $booking->allocations
                    ->sortBy('lane.number')
                    ->map(fn (LaneAllocation $allocation) => ['id' => $allocation->lane_id, 'number' => $allocation->lane->number])
                    ->values()
                    ->all(),
                'closedLaneNumbers' => $this->closedLaneNumbers($booking),
            ])
            ->all();
    }

    /**
     * What a session can be: its length is chosen in steps, up to a limit.
     *
     * @return array{stepMinutes: int, maxMinutes: int, maxPlayersPerLane: int}
     */
    public function sessionRules(): array
    {
        return [
            'stepMinutes' => config('bowling.session_step_minutes'),
            'maxMinutes' => config('bowling.max_session_minutes'),
            'maxPlayersPerLane' => config('bowling.max_players_per_lane'),
        ];
    }

    private function state(Lane $lane, ?LaneAllocation $current): LaneCardState
    {
        return match (true) {
            ! $lane->isOpen() => LaneCardState::OutOfOrder,
            $current === null => LaneCardState::Free,
            $current->status === AllocationStatus::Held => LaneCardState::Held,
            $current->closed_for_booking_id !== null => LaneCardState::ClosedForReservation,
            $current->closure_reason !== null => LaneCardState::Maintenance,
            $current->booking === null => LaneCardState::Blocked,
            $current->booking->status === BookingStatus::Confirmed => LaneCardState::Reserved,
            default => LaneCardState::InPlay,
        };
    }

    /**
     * The lanes of a reservation still waiting to check in that have been
     * marked out of order: the group must be moved off them first.
     *
     * @return list<int>
     */
    private function closedLaneNumbers(Booking $booking): array
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return [];
        }

        return $booking->allocations
            ->filter(fn (LaneAllocation $allocation) => $allocation->lane->needsMovingAt($allocation->starts_at))
            ->pluck('lane.number')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * How many reservations still waiting to check in each lane has coming
     * up, keyed by lane id. Shown when staff are about to close the lane.
     *
     * @return array<int, int>
     */
    private function reservationsAhead(CarbonInterface $now): array
    {
        return LaneAllocation::query()
            ->occupying()
            ->where('ends_at', '>', $now)
            ->whereHas('booking', function (Builder $query) {
                $query->where('status', BookingStatus::Confirmed->value)
                    ->where('source', '!=', BookingSource::WalkIn->value);
            })
            ->get(['lane_id', 'booking_id'])
            ->groupBy('lane_id')
            ->map(fn ($allocations) => $allocations->unique('booking_id')->count())
            ->all();
    }

    // A party that has checked in and is playing: the only kind of session that can be extended or ended early.
    private function isRunningSession(LaneAllocation $allocation): bool
    {
        return $allocation->status === AllocationStatus::Active
            && $allocation->booking?->status === BookingStatus::CheckedIn;
    }

    /**
     * How many more minutes a session could be given, in whole session steps,
     * before one of its lanes is booked again. Null means nothing is booked
     * after it within the time the board looks ahead.
     */
    private function roomToExtend(LaneAllocation $allocation, ?int $bookedAgainAt): ?int
    {
        if ($bookedAgainAt === null) {
            return null;
        }

        $step = config('bowling.session_step_minutes');
        $freeMinutes = max(0, intdiv($bookedAgainAt - $allocation->ends_at->getTimestamp(), 60));

        return intdiv($freeMinutes, $step) * $step;
    }

    // The booking an allocation is for: its own, or the reservation it keeps the lane empty for.
    private function party(LaneAllocation $allocation): ?Booking
    {
        return $allocation->booking ?? $allocation->closedForBooking;
    }

    /**
     * Allocations that still take up their lane and haven't finished, up to
     * the lookahead limit.
     *
     * @param  Builder<LaneAllocation>  $query
     * @return Builder<LaneAllocation>
     */
    private function upcoming(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->occupying()
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<', $now->copy()->addHours(self::LOOKAHEAD_HOURS));
    }
}
