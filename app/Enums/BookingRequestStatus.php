<?php

namespace App\Enums;

/**
 * Where a customer's reservation request has got to.
 */
enum BookingRequestStatus: string
{
    // Not dealt with yet. Once its start time has passed it counts as missed.
    case Pending = 'pending';
    // Staff called the customer and made the reservation.
    case Confirmed = 'confirmed';
    case Declined = 'declined';
}
