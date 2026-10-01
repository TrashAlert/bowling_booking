<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';       // Waiting for payment
    case Confirmed = 'confirmed';   // Paid or approved, not arrived yet
    case CheckedIn = 'checked_in';  // Arrived and bowling
    case Completed = 'completed';   // Finished
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
