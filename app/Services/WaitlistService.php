<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Customer;
use App\Models\Package;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public function __construct(
        private BookingService $bookings,
        private LaneAvailability $availability,
    ) {
    }

    // Add a walk-in party to the end of the line.
    public function join(Customer $customer, Package $package, int $partySize): WaitlistEntry
    {
        return WaitlistEntry::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'party_size' => $partySize,
            'status' => WaitlistStatus::Waiting,
        ]);
    }

    // Add a party we have no customer record for yet, e.g. a walk-in at the desk.
    public function joinAsNewCustomer(string $name, ?string $phone, Package $package, int $partySize): WaitlistEntry
    {
        return DB::transaction(function () use ($name, $phone, $package, $partySize) {
            $customer = Customer::create(['name' => $name, 'phone' => $phone]);

            return $this->join($customer, $package, $partySize);
        });
    }

    /**
     * Give free lanes to waiting parties, first come first served.
     *
     * A party is only called if enough lanes are free for its WHOLE session,
     * so a walk-in can never run into an upcoming reservation. If the party at
     * the front doesn't fit (too big, or wants too long), a party behind it
     * that does fit is called instead.
     *
     * Calling a party holds its lanes for a few minutes while they come to the
     * desk. Returns the parties that were called.
     *
     * @return array<int, WaitlistEntry>
     */
    public function callNextParties(): array
    {
        $called = [];

        $waiting = WaitlistEntry::query()
            ->where('status', WaitlistStatus::Waiting->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with('package')
            ->get();

        foreach ($waiting as $entry) {
            $start = CarbonImmutable::now();

            // Stop early if every lane is busy right now.
            if ($this->availability->freeLaneCount($start, $start->addMinute()) === 0) {
                break;
            }

            $end = $start->addMinutes($entry->package->minutes);
            $needed = $this->bookings->lanesNeeded($entry->package, $entry->party_size);

            if ($this->availability->freeLaneCount($start, $end) < $needed) {
                continue;
            }

            if ($calledEntry = $this->call($entry, $start)) {
                $called[] = $calledEntry;
            }
        }

        return $called;
    }

    /**
     * The called party has arrived at the desk: start their session.
     *
     * @throws InvalidStateException if they weren't called, or their call already expired.
     */
    public function seat(WaitlistEntry $entry): WaitlistEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== WaitlistStatus::Called || $entry->booking?->status !== BookingStatus::Pending) {
                throw new InvalidStateException('This party is not currently called, or their call has expired.');
            }

            $entry->booking->allocations()
                ->where('status', AllocationStatus::Held->value)
                ->update(['status' => AllocationStatus::Active->value, 'held_until' => null]);

            $entry->booking->update(['status' => BookingStatus::CheckedIn]);

            $entry->update(['status' => WaitlistStatus::Seated, 'seated_at' => now()]);

            return $entry;
        });
    }

    // The party left or removed themselves from the line.
    public function leave(WaitlistEntry $entry): void
    {
        $this->removeFromLine($entry, WaitlistStatus::Left);
    }

    // Staff gave up on a party, e.g. they were called and didn't turn up.
    public function skip(WaitlistEntry $entry): void
    {
        $this->removeFromLine($entry, WaitlistStatus::Skipped);
    }

    /**
     * Called parties who didn't check in within the time limit lose their turn,
     * and their held lanes are freed. Returns how many were skipped.
     */
    public function skipExpiredCalls(): int
    {
        $cutoff = now()->subMinutes(config('bowling.waitlist_checkin_minutes'));

        $expired = WaitlistEntry::query()
            ->where('status', WaitlistStatus::Called->value)
            ->where('called_at', '<', $cutoff)
            ->get();

        foreach ($expired as $entry) {
            $this->removeFromLine($entry, WaitlistStatus::Skipped);
        }

        return $expired->count();
    }

    // Hold lanes for this party and mark them as called. Returns null if
    // someone else got there first.
    private function call(WaitlistEntry $entry, CarbonImmutable $start): ?WaitlistEntry
    {
        try {
            return DB::transaction(function () use ($entry, $start) {
                // Lock the entry so two processes can't call the same party at once.
                $entry = WaitlistEntry::query()->with(['customer', 'package'])->lockForUpdate()->find($entry->id);

                if ($entry?->status !== WaitlistStatus::Waiting) {
                    return null;
                }

                $booking = $this->bookings->book(
                    $entry->customer,
                    $entry->package,
                    $entry->party_size,
                    $start,
                    BookingSource::WalkIn,
                    hold: true,
                    holdMinutes: config('bowling.waitlist_checkin_minutes'),
                );

                $entry->update([
                    'status' => WaitlistStatus::Called,
                    'booking_id' => $booking->id,
                    'called_at' => now(),
                ]);

                return $entry;
            });
        } catch (NoLaneAvailableException) {
            // The lane was taken a moment ago; this party stays in line.
            return null;
        }
    }

    // Take a party out of the line and free any lanes held for them.
    private function removeFromLine(WaitlistEntry $entry, WaitlistStatus $newStatus): void
    {
        DB::transaction(function () use ($entry, $newStatus) {
            $entry = WaitlistEntry::query()->lockForUpdate()->find($entry->id);

            if (! $entry || ! in_array($entry->status->value, WaitlistStatus::inLine(), true)) {
                return;
            }

            if ($entry->booking?->status === BookingStatus::Pending) {
                $entry->booking->allocations()
                    ->where('status', AllocationStatus::Held->value)
                    ->update(['status' => AllocationStatus::Released->value]);

                $entry->booking->update(['status' => BookingStatus::Cancelled]);
            }

            $entry->update(['status' => $newStatus]);
        });
    }
}
