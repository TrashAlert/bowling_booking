<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Models\WaitlistDeposit;
use App\Services\WaitlistDeposits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WaitlistDepositController extends Controller
{
    /**
     * Show a party what it is about to pay. A deposit that is already paid
     * goes straight to the party's place in line.
     */
    public function show(WaitlistDeposit $deposit, WaitlistDeposits $deposits): Response|RedirectResponse
    {
        if ($deposit->entry !== null) {
            return to_route('waitlist.show', ['entry' => $deposit->entry->token]);
        }

        abort_if($deposits->payPageExpired($deposit), 410);

        return Inertia::render('waitlist/deposit', [
            'deposit' => [
                'name' => $deposit->name,
                'partySize' => $deposit->party_size,
                'minutes' => $deposit->minutes,
                'games' => $deposit->games,
                'amountCents' => $deposit->amount_cents,
            ],
            'payment' => [
                // True while no real provider is connected and Pay takes no money.
                'standIn' => $deposits->paymentIsStandIn(),
                // True once the party has been sent to the provider to pay
                // and we have not yet heard that it did.
                'awaiting' => $deposit->payment_reference !== null,
            ],
        ]);
    }

    /**
     * The party pressed Pay: hand the deposit to the payment provider.
     *
     * A provider that takes the money on the spot (as the stand-in pretends
     * to) puts the party in line at once. Otherwise the party is sent to
     * the provider's own payment page, and is put in line when the provider
     * tells us it has paid (see WaitlistDepositNoticeController). A party
     * that comes back to pay when it can no longer join, such as after
     * closing time, is turned away (see WaitlistDeposits::ensureCanPay()).
     */
    public function store(WaitlistDeposit $deposit, WaitlistDeposits $deposits): HttpResponse
    {
        abort_if($deposits->payPageExpired($deposit), 410);

        // Checked again, as the party may come back to pay a while later:
        // the venue may have closed, its session may no longer fit before
        // closing time, or the wait may have grown past the limit.
        try {
            $deposits->ensureCanPay($deposit);
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['closed' => $exception->getMessage()]);
        }

        $payment = $deposits->beginPayment($deposit);

        if (! $payment->paid) {
            return Inertia::location($payment->redirectUrl);
        }

        return to_route('waitlist.show', ['entry' => $deposits->confirmPayment($deposit)->token]);
    }
}
