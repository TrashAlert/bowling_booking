<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An action was asked for that the booking or waitlist entry isn't in the
 * right status for, e.g. seating a party whose call has expired. The message
 * is safe to show to staff.
 */
class InvalidStateException extends RuntimeException
{
    //
}
