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

    // Kept empty in the hour before a reservation starts on this lane.
    case ClosedForReservation = 'closed_for_reservation';

    // Closed for a short job such as re-oiling; reopens by itself.
    case Maintenance = 'maintenance';

    // Blocked with no booking, e.g. a league night.
    case Blocked = 'blocked';

    case OutOfOrder = 'out_of_order';
}
