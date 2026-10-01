<?php

namespace App\Enums;

enum BookingSource: string
{
    case Online = 'online';
    case WalkIn = 'walk_in';
    case Phone = 'phone';
}
