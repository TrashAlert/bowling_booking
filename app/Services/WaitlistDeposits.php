<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\Payments\DepositPaymentProvider;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\StandInPaymentProvider;
use Illuminate\Support\Facades\DB;

/**
 * Joining the waitlist online. While a lane is free for the party no deposit
 * is asked for and it goes straight into the line. Otherwise it pays a
 * deposit first and is only put in line once that is paid, unless an admin
 * has turned the deposit off: then every party joins for free. It can only be
 * done while the venue is open, and while a lane can be found for the
 * session at all. Walk-ins added by staff at the counter don't come through
 * here and pay no deposit.
 */
class WaitlistDeposits
{
    public function __construct(
        private WaitlistService $waitlist,
        private WaitlistEstimate $estimate,
        private OpeningHours $hours,
        private WaitlistSettings $settings,
        private DepositPaymentProvider $payments,
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
     * @throws NoLaneAvailableException when no lane can be found for the session.
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
     * for is not free for it. Nothing either way while the deposit is off.
     *
     * @throws NoLaneAvailableException when no lane can be found for the session, so there is no line worth paying to join.
     */
    public function amountDue(int $minutes): int
    {
        $wait = $this->estimate->forNewParty()[$minutes] ?? null;

        if ($wait === null) {
            throw new NoLaneAvailableException(1, 0);
        }

        return $wait === 0 ? 0 : $this->depositCents();
    }

    /**
     * The deposit a party that has to wait pays, in cents: zero while an
     * admin has the deposit turned off.
     */
    public function depositCents(): int
    {
        return $this->settings->depositRequired() ? config('bowling.waitlist_deposit_cents') : 0;
    }

    /**
     * Whether deposits are taken by the stand-in, which takes no money.
     */
    public function paymentIsStandIn(): bool
    {
        return $this->payments instanceof StandInPaymentProvider;
    }

    /**
     * The party pressed Pay: ask the payment provider to take the deposit,
     * and note which provider and which payment of its it is. The answer
     * says whether it is paid already or the party must go to the
     * provider's page; either way the party is only put in line by
     * confirmPayment().
     */
    public function beginPayment(WaitlistDeposit $deposit): PaymentStart
    {
        if ($deposit->isPaid()) {
            return PaymentStart::paidNow($deposit->payment_reference);
        }

        $start = $this->payments->begin(
            $deposit,
            route('waitlist.deposit.show', ['deposit' => $deposit->token]),
            route('waitlist.deposit.notice', ['provider' => $this->payments->name()]),
        );

        $deposit->update([
            'payment_provider' => $this->payments->name(),
            'payment_reference' => $start->reference,
        ]);

        return $start;
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
