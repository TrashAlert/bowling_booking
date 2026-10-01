<?php

namespace App\Enums;

enum AllocationStatus: string
{
    // Temporarily reserved while the customer pays. Expires at held_until.
    case Held = 'held';

    // Confirmed: the lane is taken for this period.
    case Active = 'active';

    // Cancelled or expired. No longer blocks the lane.
    case Released = 'released';
}
