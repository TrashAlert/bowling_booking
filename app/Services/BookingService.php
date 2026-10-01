<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Lane;
use App\Models\Package;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    // PostgreSQL's error code when our no-overlap rule blocks an insert.
    private const OVERLAP_ERROR = '23P01';

    public function __construct(private LaneAvailability $availability)
    {
    }

    // A party bigger than one lane's limit gets more lanes, e.g. 10 people at 6 per lane = 2.
    public function lanesNeeded(Package $package, int $partySize): int
    {
        return (int) ceil($partySize / $package->max_players);
    }

    /**
     * Book enough lanes for the party, starting at $startsAt.
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
        Package $package,
        int $partySize,
        CarbonImmutable $startsAt,
        BookingSource $source,
        bool $hold = false,
        ?int $holdMinutes = null,
    ): Booking {
        $endsAt = $startsAt->addMinutes($package->minutes);
        $needed = $this->lanesNeeded($package, $partySize);
        $heldUntil = $hold ? now()->addMinutes($holdMinutes ?? config('bowling.hold_minutes')) : null;

        return DB::transaction(function () use ($customer, $package, $partySize, $startsAt, $endsAt, $source, $hold, $heldUntil, $needed) {
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'party_size' => $partySize,
                'source' => $source,
                'status' => $hold ? BookingStatus::Pending : BookingStatus::Confirmed,
                // The package price is per lane.
                'total_cents' => $package->price_cents * $needed,
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
