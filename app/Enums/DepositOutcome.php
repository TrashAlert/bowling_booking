<?php

namespace App\Enums;

/**
 * What became of a deposit that was paid to join the waitlist online.
 */
enum DepositOutcome: string
{
    // The party is still in line, so nothing is decided yet.
    case Held = 'held';
    // The party was seated: the deposit counts toward its bill at the counter.
    case Applied = 'applied';
    // The party left before it was called: the deposit goes back to it.
    case RefundDue = 'refund_due';
    // The party was called and didn't check in: the deposit is kept.
    case Forfeited = 'forfeited';
}
