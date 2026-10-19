<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\LaneAllocation;
use Illuminate\Support\Facades\DB;

class BookingCleanup
{
    public function __construct(private ActivityLog $log) {}

    /**
     * Lanes held for a called walk-in that never checked in: free them and
     * cancel the booking. Returns how many lane allocations were released.
     */
    public function releaseExpiredHolds(): int
    {
        $cutoff = now();

        return DB::transaction(function () use ($cutoff) {
            $expired = LaneAllocation::query()
                ->where('status', AllocationStatus::Held->value)
                ->where('held_until', '<', $cutoff);

            $held = (clone $expired)->with(['lane', 'booking.customer'])->get();

            $released = $expired->update(['status' => AllocationStatus::Released->value]);

            Booking::query()
                ->whereIn('id', $held->pluck('booking_id')->filter()->unique())
                ->where('status', BookingStatus::Pending->value)
                ->update(['status' => BookingStatus::Cancelled->value]);

            foreach ($held->groupBy('booking_id') as $allocations) {
                $booking = $allocations->first()->booking;

                $this->log->record('waitlist.hold_released', sprintf(
                    'Freed %s held for %s: the time to check in ran out.',
                    $this->log->lanes($allocations->pluck('lane.number')),
                    $booking === null ? 'a party' : $this->log->party($booking->customer->name, $booking->party_size),
                ), $booking);
            }

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
            $bookings = Booking::query()
                ->where('status', BookingStatus::Confirmed->value)
                ->whereHas('allocations', function ($query) use ($cutoff) {
                    $query->where('status', AllocationStatus::Active->value)
                        ->where('starts_at', '<=', $cutoff);
                })
                // Locked, so a check-in at the same moment either lands first
                // (and the booking is no longer picked up here) or waits.
                ->lockForUpdate()
                ->get();

            if ($bookings->isEmpty()) {
                return 0;
            }

            $bookings->load(['customer', 'allocations.lane']);

            LaneAllocation::query()
                ->whereIn('booking_id', $bookings->modelKeys())
                ->where('status', AllocationStatus::Active->value)
                ->update(['status' => AllocationStatus::Released->value]);

            $marked = Booking::query()
                ->whereKey($bookings->modelKeys())
                ->update(['status' => BookingStatus::NoShow->value]);

            foreach ($bookings as $booking) {
                $this->log->record('reservation.no_show', sprintf(
                    'Marked %s as a no-show for %s and freed %s.',
                    $this->log->party($booking->customer->name, $booking->party_size),
                    $this->log->when($booking->allocations->min('starts_at')),
                    $this->log->lanes($booking->allocations->pluck('lane.number')),
                ), $booking);
            }

            return $marked;
        });
    }

    /**
     * Sessions that have run their time: a checked-in booking with no lane
     * time left becomes completed. Returns how many bookings were completed.
     */
    public function completeFinishedSessions(): int
    {
        return DB::transaction(function () {
            $bookings = Booking::query()
                ->where('status', BookingStatus::CheckedIn->value)
                ->whereDoesntHave('allocations', function ($query) {
                    $query->occupying()->where('ends_at', '>', now());
                })
                ->lockForUpdate()
                ->get();

            if ($bookings->isEmpty()) {
                return 0;
            }

            $bookings->load(['customer', 'allocations.lane']);

            $completed = Booking::query()
                ->whereKey($bookings->modelKeys())
                ->update(['status' => BookingStatus::Completed->value]);

            foreach ($bookings as $booking) {
                $this->log->record('session.completed', sprintf(
                    '%s finished playing on %s.',
                    $this->log->party($booking->customer->name, $booking->party_size),
                    $this->log->lanes($booking->allocations->pluck('lane.number')->unique()),
                ), $booking);
            }

            return $completed;
        });
    }
}
