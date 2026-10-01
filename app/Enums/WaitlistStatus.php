<?php

namespace App\Enums;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';   // In line
    case Called = 'called';     // A lane is ready; they have a few minutes to check in
    case Seated = 'seated';     // Checked in and given a lane (now has a booking)
    case Skipped = 'skipped';   // Didn't check in when called
    case Left = 'left';         // Removed themselves or left before being called

    // Statuses that still count as being in line.
    public static function inLine(): array
    {
        return [self::Waiting->value, self::Called->value];
    }
}
