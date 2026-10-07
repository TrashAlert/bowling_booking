<?php

namespace App\Services\Payments;

/**
 * What happened when a payment provider was asked to take a deposit: either
 * it is paid already, or the customer has to go to the provider's own page.
 */
final readonly class PaymentStart
{
    private function __construct(
        public bool $paid,
        public ?string $redirectUrl,
        public ?string $reference,
    ) {}

    /**
     * The deposit was paid on the spot. $reference is the provider's own
     * name for the payment, if it has one.
     */
    public static function paidNow(?string $reference = null): self
    {
        return new self(true, null, $reference);
    }

    /**
     * The customer has to pay on the provider's page at $url. $reference is
     * what the provider will quote when it tells us the payment was made.
     */
    public static function redirectTo(string $url, string $reference): self
    {
        return new self(false, $url, $reference);
    }
}
