<?php

namespace App\Enums;

// What a lane is doing right now, as shown on the staff lane board.
enum LaneCardState: string
{
    case Free = 'free';

    // A party has checked in and is bowling.
    case InPlay = 'in_play';

    // Held for a called walk-in (or an unpaid online booking) until held_until.
    case Held = 'held';

    // A reservation has started but the party hasn't checked in yet.
    case Reserved = 'reserved';

    // Blocked with no booking, e.g. a league night or maintenance.
    case Blocked = 'blocked';

    case OutOfOrder = 'out_of_order';
}
