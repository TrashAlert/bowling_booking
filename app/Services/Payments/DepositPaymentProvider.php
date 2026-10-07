<?php

namespace App\Services\Payments;

use App\Models\WaitlistDeposit;
use Illuminate\Http\Request;

/**
 * Whoever takes the deposit a party pays to join the waitlist online: FPX,
 * DuitNow, cards, or the stand-in that takes no money.
 *
 * To connect a real provider:
 *
 * 1. Write a class that implements this interface, next to
 *    StandInPaymentProvider.
 * 2. Add it to "deposit_payments.providers" in config/bowling.php under a
 *    short name, and set BOWLING_DEPOSIT_PAYMENT_PROVIDER to that name.
 * 3. Give the provider the notice address it is handed in begin(), so it
 *    can tell us when the money has arrived.
 *
 * Nothing else in the app needs to change. The rest of the flow (the pay
 * page, putting the party in line once, the page it comes back to) is
 * already in place and only talks to this interface.
 */
interface DepositPaymentProvider
{
    /**
     * A short name for this provider, in lowercase with no spaces. It is
     * saved with each deposit and is the last part of the notice address.
     */
    public function name(): string;

    /**
     * Start taking a deposit of $deposit->amount_cents.
     *
     * Return PaymentStart::paidNow() when the money is taken on the spot.
     * Otherwise create the payment at the provider and return
     * PaymentStart::redirectTo() with the address of its payment page and
     * its own reference for the payment. The customer is sent there, and
     * comes back to $returnUrl when done. The provider must post its
     * notice of payment to $notifyUrl.
     *
     * A customer who comes back unpaid may press Pay again, so this can be
     * called more than once for one deposit. Only the latest reference is
     * kept, so reuse the earlier payment where the provider allows it.
     */
    public function begin(WaitlistDeposit $deposit, string $returnUrl, string $notifyUrl): PaymentStart;

    /**
     * Read a notice the provider posted to the notice address, and return
     * the reference of the payment it proves was paid.
     *
     * Return null for anything else: a payment that failed or is still
     * pending, or a notice whose signature does not check out. Checking
     * that the notice is really from the provider is this method's job;
     * the address is open to the internet. The same notice may arrive more
     * than once, which is safe: a party is only ever put in line once.
     */
    public function paidReference(Request $request): ?string;
}
