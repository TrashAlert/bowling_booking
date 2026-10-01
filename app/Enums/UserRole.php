<?php

namespace App\Enums;

enum UserRole: string
{
    // Works the counter: lane board, waitlist and bookings.
    case Staff = 'staff';

    // Everything staff can do, plus owner-only management.
    case Admin = 'admin';
}
