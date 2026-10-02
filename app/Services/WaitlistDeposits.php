<?php

namespace App\Services;

use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

/**
 * Joining the waitlist online. A party pays a deposit first and is only put
 * in line once it is paid. Walk-ins added by staff at the counter don't come
 * through here and pay no deposit.
 */
class WaitlistDeposits
{
    public function __construct(private WaitlistService $waitlist) {}

    /**
     * Note what a party wants and how much it has to pay. It isn't in line
     * yet, and takes no place in it.
     */
    public function start(string $name, string $phone, int $minutes, int $partySize): WaitlistDeposit
    {
        return WaitlistDeposit::create([
            'name' => $name,
            'phone' => $phone,
            'minutes' => $minutes,
            'party_size' => $partySize,
            'amount_cents' => config('bowling.waitlist_deposit_cents'),
        ]);
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
