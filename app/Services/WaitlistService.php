<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Customer;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public function __construct(
        private BookingService $bookings,
        private LaneAvailability $availability,
        private ActivityLog $log,
    ) {
    }

    // Add a walk-in party that wants to play for $minutes to the end of the line.
    public function join(Customer $customer, int $minutes, int $partySize): WaitlistEntry
    {
        return WaitlistEntry::create([
            'customer_id' => $customer->id,
            'minutes' => $minutes,
            'party_size' => $partySize,
            'status' => WaitlistStatus::Waiting,
        ]);
    }

    // Add a party we have no customer record for yet, e.g. a walk-in at the desk.
    public function joinAsNewCustomer(string $name, ?string $phone, int $minutes, int $partySize): WaitlistEntry
    {
        return DB::transaction(function () use ($name, $phone, $minutes, $partySize) {
            $customer = Customer::create(['name' => $name, 'phone' => $phone]);

            $entry = $this->join($customer, $minutes, $partySize);

            $this->log->record('waitlist.joined', sprintf(
                '%s joined the line, for %s.',
                $this->log->party($name, $partySize),
                $this->log->length($minutes),
            ), $entry, customerName: $name);

            return $entry;
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
            ->get();

        foreach ($waiting as $entry) {
            $start = CarbonImmutable::now();

            // Stop early if every lane is busy right now.
            if ($this->availability->freeLaneCount($start, $start->addMinute()) === 0) {
                break;
            }

            $end = $start->addMinutes($entry->minutes);
            $needed = $this->bookings->lanesNeeded($entry->party_size);

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
     * @throws InvalidStateException if they weren't called, their call already expired, or their lane is closed.
     */
    public function seat(WaitlistEntry $entry): WaitlistEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== WaitlistStatus::Called || $entry->booking?->status !== BookingStatus::Pending) {
                throw new InvalidStateException('This party is not currently called, or their call has expired.');
            }

            // Closing a lane for repair puts its called party back in line, so
            // this only catches a lane closed some other way in the meantime.
            $closed = $this->bookings->closedLaneNumbers($entry->booking);

            if ($closed !== []) {
                throw new InvalidStateException('Lane '.implode(', ', $closed).' is closed, so this party can\'t be seated there. Reopen the lane first.');
            }

            $entry->booking->allocations()
                ->where('status', AllocationStatus::Held->value)
                ->update(['status' => AllocationStatus::Active->value, 'held_until' => null]);

            $entry->booking->update(['status' => BookingStatus::CheckedIn]);

            $entry->update(['status' => WaitlistStatus::Seated, 'seated_at' => now()]);

            $this->log->record('waitlist.seated', sprintf(
                'Seated %s on %s.',
                $this->log->party($entry->customer->name, $entry->party_size),
                $this->log->lanes($entry->booking->allocations()->with('lane')->get()->pluck('lane.number')),
            ), $entry);

            return $entry;
        });
    }

    // The party left or removed themselves from the line.
    public function leave(WaitlistEntry $entry): void
    {
        $this->removeFromLine($entry, WaitlistStatus::Left, 'waitlist.left', '%s left the line.');
    }

    // Staff gave up on a party, e.g. they were called and didn't turn up.
    public function skip(WaitlistEntry $entry): void
    {
        $this->removeFromLine($entry, WaitlistStatus::Skipped, 'waitlist.skipped', 'Skipped %s.');
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
            $this->removeFromLine($entry, WaitlistStatus::Skipped, 'waitlist.missed_call', '%s did not check in in time and lost its turn.');
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
                $entry = WaitlistEntry::query()->with('customer')->lockForUpdate()->find($entry->id);

                if ($entry?->status !== WaitlistStatus::Waiting) {
                    return null;
                }

                $booking = $this->bookings->book(
                    $entry->customer,
                    $entry->minutes,
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

                $this->log->record('waitlist.called', sprintf(
                    'Called %s to %s.',
                    $this->log->party($entry->customer->name, $entry->party_size),
                    $this->log->lanes($booking->allocations->pluck('lane.number')),
                ), $entry);

                return $entry;
            });
        } catch (NoLaneAvailableException) {
            // The lane was taken a moment ago; this party stays in line.
            return null;
        }
    }

    // Take a party out of the line and free any lanes held for them, and log
    // it as $action, with the party's name put into $summary.
    private function removeFromLine(WaitlistEntry $entry, WaitlistStatus $newStatus, string $action, string $summary): void
    {
        DB::transaction(function () use ($entry, $newStatus, $action, $summary) {
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

            $this->log->record($action, sprintf($summary, $this->log->party($entry->customer->name, $entry->party_size)), $entry, customerName: $entry->customer->name);
        });
    }
}
