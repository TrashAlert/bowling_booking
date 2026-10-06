<?php

namespace App\Services;

use App\Enums\LaneStatus;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LaneAvailability
{
    /**
     * Open lanes with nothing booked between $start and $end, lowest number first.
     *
     * @return Collection<int, Lane>
     */
    public function freeLanes(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Lane::query()
            ->where('status', LaneStatus::Open->value)
            ->whereDoesntHave('allocations', function (Builder $query) use ($start, $end) {
                $query->occupying()->overlapping($start, $end);
            })
            ->orderBy('number')
            ->get();
    }

    public function freeLaneCount(CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->freeLanes($start, $end)->count();
    }

    /**
     * Whether any lane is open at all, busy or not. When none is, every lane
     * is out of order, or the venue has none yet.
     */
    public function hasOpenLane(): bool
    {
        return Lane::query()->where('status', LaneStatus::Open->value)->exists();
    }

    /**
     * When a lane reserved from $startsAt stops taking anyone: the lead time
     * before the start, or now if the reservation is sooner than that.
     */
    public function closesAt(CarbonImmutable $startsAt): CarbonImmutable
    {
        return $startsAt
            ->subMinutes(config('bowling.reservation_lead_minutes'))
            ->max(CarbonImmutable::now());
    }

    /**
     * Every lane, lowest number first, with whether it can take a reservation
     * of $minutes from $startsAt. A lane must be open and empty from the
     * moment it would close until the session ends. Pass the booking being
     * rescheduled as $ignoring so its own lanes don't count as taken.
     *
     * @return Collection<int, array{lane: Lane, available: bool}>
     */
    public function lanesForReservation(CarbonImmutable $startsAt, int $minutes, ?Booking $ignoring = null): Collection
    {
        $startsAt = $startsAt->utc();

        $busyLaneIds = LaneAllocation::query()
            ->occupying()
            ->overlapping($this->closesAt($startsAt), $startsAt->addMinutes($minutes))
            ->get(['lane_id', 'booking_id', 'closed_for_booking_id'])
            ->reject(fn (LaneAllocation $allocation) => $ignoring !== null
                && in_array($ignoring->id, [$allocation->booking_id, $allocation->closed_for_booking_id], true))
            ->pluck('lane_id')
            ->unique();

        return Lane::query()
            ->orderBy('number')
            ->get()
            ->map(fn (Lane $lane) => [
                'lane' => $lane,
                'available' => $lane->isOpen() && ! $busyLaneIds->contains($lane->id),
            ]);
    }
}
