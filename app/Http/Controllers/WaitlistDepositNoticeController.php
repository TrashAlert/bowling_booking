<?php

namespace App\Http\Controllers;

use App\Models\WaitlistDeposit;
use App\Services\Payments\DepositPaymentProvider;
use App\Services\WaitlistDeposits;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WaitlistDepositNoticeController extends Controller
{
    /**
     * A payment provider telling us a deposit has been paid: put the party
     * in line. The provider checks the notice is its own and names the
     * payment; a notice that proves nothing, or names a payment we don't
     * know, changes nothing.
     *
     * A paid party is put in line even if the venue has closed since it
     * pressed Pay: its money has been taken.
     */
    public function __invoke(Request $request, string $provider, DepositPaymentProvider $payments, WaitlistDeposits $deposits): Response
    {
        abort_unless($provider === $payments->name(), 404);

        $reference = $payments->paidReference($request);

        abort_if($reference === null, 400);

        $deposits->confirmPayment(
            WaitlistDeposit::query()
                ->where('payment_provider', $payments->name())
                ->where('payment_reference', $reference)
                ->firstOrFail(),
        );

        return response()->noContent();
    }
}
