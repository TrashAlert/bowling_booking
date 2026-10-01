<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Lane;
use App\Models\LaneAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BookingService
{
    // PostgreSQL's error code when our no-overlap rule blocks an insert.
    private const OVERLAP_ERROR = '23P01';

    public function __construct(
        private LaneAvailability $availability,
        private LaneClosures $closures,
    ) {
    }

    // A party bigger than one lane's limit gets more lanes, e.g. 10 people at 6 per lane = 2.
    public function lanesNeeded(int $partySize): int
    {
        return (int) ceil($partySize / config('bowling.max_players_per_lane'));
    }

    /**
     * Book enough lanes for the party for $minutes, starting at $startsAt.
     *
     * $hold = true reserves the lanes only for a few minutes and leaves the
     * booking pending: for online bookings awaiting payment, and for walk-ins
     * who have been called but not yet checked in. $holdMinutes overrides the
     * default hold length from config/bowling.php.
     *
     * @throws NoLaneAvailableException when there aren't enough free lanes.
     */
    public function book(
        Customer $customer,
        int $minutes,
        int $partySize,
        CarbonImmutable $startsAt,
        BookingSource $source,
        bool $hold = false,
        ?int $holdMinutes = null,
    ): Booking {
        $endsAt = $startsAt->addMinutes($minutes);
        $needed = $this->lanesNeeded($partySize);
        $heldUntil = $hold ? now()->addMinutes($holdMinutes ?? config('bowling.hold_minutes')) : null;

        return DB::transaction(function () use ($customer, $minutes, $partySize, $startsAt, $endsAt, $source, $hold, $heldUntil, $needed) {
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'minutes' => $minutes,
                'party_size' => $partySize,
                'source' => $source,
                'status' => $hold ? BookingStatus::Pending : BookingStatus::Confirmed,
                // Sessions aren't priced yet.
                'total_cents' => 0,
            ]);

            $taken = 0;

            foreach ($this->availability->freeLanes($startsAt, $endsAt) as $lane) {
                if ($this->tryToTake($lane, $booking, $startsAt, $endsAt, $hold, $heldUntil)) {
                    $taken++;
                }

                if ($taken === $needed) {
                    return $booking->load('allocations.lane');
                }
            }

            // Not enough lanes. Throwing here undoes everything in this
            // transaction, including the booking row created above.
            throw new NoLaneAvailableException($needed, $taken);
        });
    }

    /**
     * Reserve the lanes staff chose, for $minutes from $startsAt.
     *
     * Unlike book(), the lanes are picked by hand and the party size doesn't
     * decide how many there are. Each lane also stops taking anyone from the
     * lead time before the start (see LaneAvailability::closesAt), so it must
     * be empty from then until the session ends. Every lane is reserved, or
     * none is.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws NoLaneAvailableException naming the first lane that isn't free.
     */
    public function reserve(
        Customer $customer,
        Collection $lanes,
        int $minutes,
        int $partySize,
        CarbonImmutable $startsAt,
        ?string $notes = null,
        BookingSource $source = BookingSource::Phone,
    ): Booking {
        return DB::transaction(function () use ($customer, $lanes, $minutes, $partySize, $startsAt, $notes, $source) {
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'minutes' => $minutes,
                'party_size' => $partySize,
                'source' => $source,
                'status' => BookingStatus::Confirmed,
                // Sessions aren't priced yet.
                'total_cents' => 0,
                'notes' => $notes,
            ]);

            $this->occupy($booking, $lanes, $startsAt, $startsAt->addMinutes($minutes));

            return $booking->load('allocations.lane');
        });
    }

    // Reserve lanes for someone we have no customer record for yet.
    public function reserveForNewCustomer(
        string $name,
        ?string $phone,
        Collection $lanes,
        int $minutes,
        int $partySize,
        CarbonImmutable $startsAt,
        ?string $notes = null,
    ): Booking {
        return DB::transaction(function () use ($name, $phone, $lanes, $minutes, $partySize, $startsAt, $notes) {
            $customer = Customer::create(['name' => $name, 'phone' => $phone]);

            return $this->reserve($customer, $lanes, $minutes, $partySize, $startsAt, $notes);
        });
    }

    /**
     * Change a confirmed reservation: who it is for, when, how long, how many
     * people and which lanes. If the new lanes aren't all free, nothing
     * changes and the reservation stays exactly as it was.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws InvalidStateException if the reservation is no longer confirmed.
     * @throws NoLaneAvailableException naming the first lane that isn't free.
     */
    public function reschedule(
        Booking $booking,
        string $name,
        ?string $phone,
        Collection $lanes,
        int $minutes,
        int $partySize,
        CarbonImmutable $startsAt,
        ?string $notes = null,
    ): Booking {
        return DB::transaction(function () use ($booking, $name, $phone, $lanes, $minutes, $partySize, $startsAt, $notes) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new InvalidStateException('Only a confirmed reservation can be changed.');
            }

            // The old rows go first so the reservation doesn't block itself.
            // If a new lane turns out to be taken, the transaction puts them back.
            $booking->allocations()->delete();
            $booking->closures()->delete();

            $booking->customer->update(['name' => $name, 'phone' => $phone]);
            $booking->update(['minutes' => $minutes, 'party_size' => $partySize, 'notes' => $notes]);

            $this->occupy($booking, $lanes, $startsAt, $startsAt->addMinutes($minutes));

            return $booking->load('allocations.lane');
        });
    }

    /**
     * Cancel a booking that hasn't started and free its lanes straight away.
     *
     * @throws InvalidStateException if the party has already arrived, or the booking is already over.
     */
    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
                throw new InvalidStateException('This booking can no longer be cancelled.');
            }

            $booking->allocations()->occupying()->update(['status' => AllocationStatus::Released->value]);
            $booking->closures()->occupying()->update(['status' => AllocationStatus::Released->value]);

            $booking->update(['status' => BookingStatus::Cancelled]);

            return $booking;
        });
    }

    /**
     * Give a party that is playing $minutes more on every lane it has.
     *
     * Only a session that is under way can be extended: the party has checked
     * in and its time hasn't run out. Every lane must be free for the extra
     * time, which also keeps it clear of the hour a lane is closed before a
     * reservation. If one lane can't be extended, none is.
     *
     * @throws InvalidStateException if the session hasn't started or has already ended.
     * @throws NoLaneAvailableException naming the first lane that is booked too soon after.
     */
    public function extend(Booking $booking, int $minutes): Booking
    {
        return DB::transaction(function () use ($booking, $minutes) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            $allocations = $booking->allocations()
                ->where('status', AllocationStatus::Active->value)
                ->where('ends_at', '>', now())
                ->with('lane')
                ->get();

            if ($booking->status !== BookingStatus::CheckedIn || $allocations->isEmpty()) {
                throw new InvalidStateException('Only a session that is still running can be extended.');
            }

            foreach ($allocations->sortBy('lane.number')->values() as $extended => $allocation) {
                try {
                    // A savepoint, so a refused update leaves the outer transaction usable.
                    DB::transaction(fn () => $allocation->update([
                        'ends_at' => $allocation->ends_at->addMinutes($minutes),
                    ]));
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                        throw new NoLaneAvailableException($allocations->count(), $extended, $allocation->lane->number);
                    }

                    throw $e;
                }
            }

            $booking->update(['minutes' => $booking->minutes + $minutes]);

            return $booking->load('allocations.lane');
        });
    }

    /**
     * The party on a lane is leaving before its time is up: free that lane
     * now. A party on several lanes keeps the others, and its booking is
     * completed once the last of them has ended.
     *
     * @throws InvalidStateException if nobody who has checked in is playing on the lane.
     */
    public function endSessionOnLane(Lane $lane): Booking
    {
        return DB::transaction(function () use ($lane) {
            $allocation = $lane->allocations()
                ->where('status', AllocationStatus::Active->value)
                ->whereNotNull('booking_id')
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->lockForUpdate()
                ->first();

            $booking = $allocation === null
                ? null
                : Booking::query()->lockForUpdate()->find($allocation->booking_id);

            if ($allocation === null || $booking?->status !== BookingStatus::CheckedIn) {
                throw new InvalidStateException('There is no running session on this lane to end.');
            }

            // Times are kept to the second. A session ended in the very second
            // it began would have no length left, which the database refuses,
            // so that one is released instead.
            $endedAt = now()->startOfSecond();
            $wasDueToEnd = $allocation->ends_at;

            $allocation->update($endedAt > $allocation->starts_at
                ? ['ends_at' => $endedAt]
                : ['status' => AllocationStatus::Released]);

            // A re-oil or the like that was waiting for this session starts now instead.
            $this->closures->pullForward($lane, $wasDueToEnd);

            $stillPlaying = $booking->allocations()
                ->where('status', AllocationStatus::Active->value)
                ->where('ends_at', '>', now())
                ->exists();

            if (! $stillPlaying) {
                $booking->update(['status' => BookingStatus::Completed]);
            }

            return $booking;
        });
    }

    /**
     * The numbers of the lanes a booking is on that have been marked out of
     * order, lowest first. A group can't check in until it has been moved
     * off them.
     *
     * @return list<int>
     */
    public function closedLaneNumbers(Booking $booking): array
    {
        return $booking->allocations()
            ->occupying()
            ->with('lane')
            ->get()
            ->pluck('lane')
            ->reject(fn (Lane $lane) => $lane->isOpen())
            ->pluck('number')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Put a confirmed reservation on a different set of lanes without
     * changing its time: staff do this when one of its lanes has been closed.
     *
     * Lanes it keeps are left as they are and lanes it drops are freed. A lane
     * it gains is booked from the reservation's start, or from now if that
     * has passed, to its end, and must be free for that time (and, ahead of
     * time, for the hour it is closed beforehand). If a new lane isn't free,
     * nothing changes.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws InvalidStateException if the reservation is no longer confirmed, or is already over.
     * @throws NoLaneAvailableException naming the first lane that is closed or isn't free.
     */
    public function moveLanes(Booking $booking, Collection $lanes): Booking
    {
        if ($lanes->isEmpty()) {
            throw new InvalidArgumentException('A reservation needs at least one lane.');
        }

        return DB::transaction(function () use ($booking, $lanes) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $current = $booking->allocations()->occupying()->get();

            if ($booking->status !== BookingStatus::Confirmed || $current->isEmpty() || $current->max('ends_at') <= now()) {
                throw new InvalidStateException('Only a confirmed reservation that is still to come can be moved.');
            }

            foreach ($lanes as $lane) {
                if (! $lane->isOpen()) {
                    throw new NoLaneAvailableException($lanes->count(), 0, $lane->number);
                }
            }

            $dropped = $current->pluck('lane_id')->diff($lanes->pluck('id'));

            $booking->allocations()->whereIn('lane_id', $dropped)->delete();
            $booking->closures()->whereIn('lane_id', $dropped)->delete();

            $gained = $lanes->reject(fn (Lane $lane) => $current->contains('lane_id', $lane->id))->values();

            if ($gained->isNotEmpty()) {
                $this->occupy($booking, $gained, $current->min('starts_at')->max(now()->startOfSecond()), $current->max('ends_at'));
            }

            return $booking->load('allocations.lane');
        });
    }

    /**
     * The party with a reservation has arrived: start their session, so the
     * booking is no longer treated as a no-show.
     *
     * @throws InvalidStateException if the booking isn't a confirmed reservation, or one of its lanes is closed.
     */
    public function checkIn(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new InvalidStateException('Only a confirmed reservation can be checked in.');
            }

            $closed = $this->closedLaneNumbers($booking);

            if ($closed !== []) {
                throw new InvalidStateException(trans_choice(
                    'Lane :lanes is closed. Move the group to another lane before checking in.|Lanes :lanes are closed. Move the group to other lanes before checking in.',
                    count($closed),
                    ['lanes' => Arr::join($closed, ', ', ' and ')],
                ));
            }

            $booking->update(['status' => BookingStatus::CheckedIn]);

            return $booking;
        });
    }

    /**
     * Put a reservation on each of the chosen lanes from $startsAt to
     * $endsAt: a row that closes the lane beforehand, then the session itself.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws NoLaneAvailableException naming the first lane that isn't free.
     */
    private function occupy(Booking $booking, Collection $lanes, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if ($lanes->isEmpty()) {
            throw new InvalidArgumentException('A reservation needs at least one lane.');
        }

        // Times are saved as their own clock time, so make sure that clock is UTC.
        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();
        $closesAt = $this->availability->closesAt($startsAt);
        $reserved = 0;

        foreach ($lanes as $lane) {
            if (! $lane->isOpen()) {
                throw new NoLaneAvailableException($lanes->count(), $reserved, $lane->number);
            }

            try {
                // A savepoint, so a refused insert leaves the outer transaction usable.
                DB::transaction(function () use ($booking, $lane, $startsAt, $endsAt, $closesAt) {
                    if ($closesAt < $startsAt) {
                        LaneAllocation::create([
                            'lane_id' => $lane->id,
                            'closed_for_booking_id' => $booking->id,
                            'starts_at' => $closesAt,
                            'ends_at' => $startsAt,
                            'status' => AllocationStatus::Active,
                        ]);
                    }

                    $booking->allocations()->create([
                        'lane_id' => $lane->id,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'status' => AllocationStatus::Active,
                    ]);
                });
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                    throw new NoLaneAvailableException($lanes->count(), $reserved, $lane->number);
                }

                throw $e;
            }

            $reserved++;
        }
    }

    /**
     * Try to put the booking on one lane. Returns false if someone else
     * took that lane a moment ago, so the caller can try the next lane.
     */
    private function tryToTake(Lane $lane, Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt, bool $hold, $heldUntil): bool
    {
        try {
            // A transaction inside a transaction becomes a "savepoint": if this
            // insert fails, only the insert is undone, not the whole booking.
            DB::transaction(fn () => $booking->allocations()->create([
                'lane_id' => $lane->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $hold ? AllocationStatus::Held : AllocationStatus::Active,
                'held_until' => $heldUntil,
            ]));

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                return false;
            }

            throw $e;
        }
    }
}
