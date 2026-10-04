<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

/**
 * Joining the waitlist online. While a lane is free for the party no deposit
 * is asked for and it goes straight into the line. Otherwise it pays a
 * deposit first and is only put in line once that is paid. It can only be
 * done while the venue is open. Walk-ins added by staff at the counter don't
 * come through here and pay no deposit.
 */
class WaitlistDeposits
{
    public function __construct(
        private WaitlistService $waitlist,
        private WaitlistEstimate $estimate,
        private OpeningHours $hours,
    ) {}

    /**
     * Turn a party away while the venue is closed, saying when it opens.
     *
     * @throws InvalidStateException when the venue is closed now.
     */
    public function ensureOpen(): void
    {
        if (! $this->hours->isOpenAt(now())) {
            throw new InvalidStateException($this->hours->closedNotice(now()));
        }
    }

    /**
     * Note what a party wants and how much it has to pay. With nothing to
     * pay it is put in line at once; otherwise it isn't in line yet and
     * takes no place in it.
     *
     * @throws InvalidStateException when the venue is closed now.
     */
    public function start(string $name, string $phone, int $minutes, int $partySize): WaitlistDeposit
    {
        $this->ensureOpen();

        $deposit = WaitlistDeposit::create([
            'name' => $name,
            'phone' => $phone,
            'minutes' => $minutes,
            'party_size' => $partySize,
            'amount_cents' => $this->amountDue($minutes),
        ]);

        if ($deposit->amount_cents === 0) {
            $this->confirmPayment($deposit);
        }

        return $deposit->refresh();
    }

    /**
     * What a party joining now for a session of $minutes has to pay, in
     * cents: nothing while a lane is free for it straight away, and the
     * deposit once it would have to wait. A lane others are already waiting
     * for is not free for it.
     */
    public function amountDue(int $minutes): int
    {
        $wait = $this->estimate->forNewParty()[$minutes] ?? null;

        return $wait === 0 ? 0 : config('bowling.waitlist_deposit_cents');
    }

    /**
     * The deposit has been paid: put the party at the end of the line as it
     * stands now, so a slow payer doesn't keep an earlier place.
     *
     * Safe to call again for a deposit that is already paid, as a payment
     * provider may report the same payment twice: the party stays in line
     * once.
     */
    public function confirmPayment(WaitlistDeposit $deposit): WaitlistEntry
    {
        return DB::transaction(function () use ($deposit) {
            $deposit = WaitlistDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            if ($deposit->entry !== null) {
                return $deposit->entry;
            }

            $entry = $this->waitlist->joinAsNewCustomer(
                $deposit->name,
                $deposit->phone,
                $deposit->minutes,
                $deposit->party_size,
            );

            $deposit->update(['paid_at' => now(), 'waitlist_entry_id' => $entry->id]);

            return $entry;
        });
    }
}
