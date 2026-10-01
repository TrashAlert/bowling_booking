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
     *     current: array{bookingId: int|null, customerName: string|null, partySize: int|null, startsAt: string, endsAt: string, heldUntil: string|null, note: string|null}|null,
     *     next: array{startsAt: string, customerName: string|null, note: string|null}|null,
     * }>
     */
    public function lanes(): array
    {
        $now = now();

        return Lane::query()
            ->orderBy('number')
            ->with(['allocations' => function (Relation $query) use ($now) {
                $this->upcoming($query->getQuery(), $now)
                    ->orderBy('starts_at')
                    ->with('booking.customer');
            }])
            ->get()
            ->map(function (Lane $lane) use ($now) {
                $current = $lane->allocations->first(fn (LaneAllocation $allocation) => $allocation->starts_at <= $now);
                $next = $lane->allocations->first(fn (LaneAllocation $allocation) => $allocation->starts_at > $now);

                return [
                    'id' => $lane->id,
                    'number' => $lane->number,
                    'hasBumpers' => $lane->has_bumpers,
                    'isOpen' => $lane->isOpen(),
                    'state' => $this->state($lane, $current)->value,
                    'current' => $current ? [
                        'bookingId' => $current->booking_id,
                        'customerName' => $current->booking?->customer->name,
                        'partySize' => $current->booking?->party_size,
                        'startsAt' => $current->starts_at->toIso8601String(),
                        'endsAt' => $current->ends_at->toIso8601String(),
                        'heldUntil' => $current->held_until?->toIso8601String(),
                        'note' => $current->note,
                    ] : null,
                    'next' => $next ? [
                        'startsAt' => $next->starts_at->toIso8601String(),
                        'customerName' => $next->booking?->customer->name,
                        'note' => $next->note,
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
     *     laneNumbers: list<int>,
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
                'laneNumbers' => $booking->allocations->pluck('lane.number')->sort()->values()->all(),
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
            $current->booking === null => LaneCardState::Blocked,
            $current->booking->status === BookingStatus::Confirmed => LaneCardState::Reserved,
            default => LaneCardState::InPlay,
        };
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
