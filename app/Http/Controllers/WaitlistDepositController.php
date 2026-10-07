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

        return Inertia::render('waitlist/deposit', [
            'deposit' => [
                'name' => $deposit->name,
                'partySize' => $deposit->party_size,
                'minutes' => $deposit->minutes,
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
     * that comes back to pay after closing time is turned away.
     */
    public function store(WaitlistDeposit $deposit, WaitlistDeposits $deposits): HttpResponse
    {
        try {
            $deposits->ensureOpen();
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
