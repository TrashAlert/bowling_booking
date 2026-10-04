<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Models\WaitlistDeposit;
use App\Services\WaitlistDeposits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistDepositController extends Controller
{
    /**
     * Show a party what it is about to pay. A deposit that is already paid
     * goes straight to the party's place in line.
     */
    public function show(WaitlistDeposit $deposit): Response|RedirectResponse
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
        ]);
    }

    /**
     * Take the deposit as paid and put the party in line.
     *
     * This is a stand-in until a payment provider is connected: no money is
     * taken. The provider's confirmation will call the same service method.
     * A party that comes back to pay after closing time is turned away.
     */
    public function store(WaitlistDeposit $deposit, WaitlistDeposits $deposits): RedirectResponse
    {
        try {
            $deposits->ensureOpen();
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['closed' => $exception->getMessage()]);
        }

        $entry = $deposits->confirmPayment($deposit);

        return to_route('waitlist.show', ['entry' => $entry->token]);
    }
}
