<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\LaneAllocation;
use Illuminate\Support\Facades\DB;

class BookingCleanup
{
    /**
     * Online bookings that were never paid: free their lanes and cancel them.
     * Returns how many lane allocations were released.
     */
    public function releaseExpiredHolds(): int
    {
        $cutoff = now();

        return DB::transaction(function () use ($cutoff) {
            $expired = LaneAllocation::query()
                ->where('status', AllocationStatus::Held->value)
                ->where('held_until', '<', $cutoff);

            $bookingIds = (clone $expired)->pluck('booking_id')->filter()->unique();

            $released = $expired->update(['status' => AllocationStatus::Released->value]);

            Booking::query()
                ->whereIn('id', $bookingIds)
                ->where('status', BookingStatus::Pending->value)
                ->update(['status' => BookingStatus::Cancelled->value]);

            return $released;
        });
    }

    /**
     * Confirmed reservations that didn't check in within the grace period:
     * mark them as no-shows and free their lanes.
     * Returns how many bookings were marked.
     */
    public function markNoShows(): int
    {
        $cutoff = now()->subMinutes(config('bowling.no_show_grace_minutes'));

        return DB::transaction(function () use ($cutoff) {
            $bookingIds = Booking::query()
                ->where('status', BookingStatus::Confirmed->value)
                ->whereHas('allocations', function ($query) use ($cutoff) {
                    $query->where('status', AllocationStatus::Active->value)
                        ->where('starts_at', '<=', $cutoff);
                })
                ->pluck('id');

            if ($bookingIds->isEmpty()) {
                return 0;
            }

            LaneAllocation::query()
                ->whereIn('booking_id', $bookingIds)
                ->where('status', AllocationStatus::Active->value)
                ->update(['status' => AllocationStatus::Released->value]);

            return Booking::query()
                ->whereIn('id', $bookingIds)
                ->update(['status' => BookingStatus::NoShow->value]);
        });
    }
}
