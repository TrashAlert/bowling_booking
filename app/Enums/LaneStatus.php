<?php

namespace App\Enums;

enum LaneStatus: string
{
    case Open = 'open';
    case OutOfOrder = 'out_of_order';
}
