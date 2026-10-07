<?php

namespace App\Services\Payments;

use App\Models\WaitlistDeposit;
use Illuminate\Http\Request;

/**
 * The provider used until a real one is connected. It takes no money:
 * pressing Pay counts as paid at once. Never use it once deposits matter.
 */
class StandInPaymentProvider implements DepositPaymentProvider
{
    public const NAME = 'stand_in';

    public function name(): string
    {
        return self::NAME;
    }

    public function begin(WaitlistDeposit $deposit, string $returnUrl, string $notifyUrl): PaymentStart
    {
        return PaymentStart::paidNow();
    }

    /**
     * Nobody sends notices for the stand-in, so none proves anything.
     */
    public function paidReference(Request $request): ?string
    {
        return null;
    }
}
