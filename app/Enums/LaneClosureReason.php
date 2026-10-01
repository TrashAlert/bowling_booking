<?php

namespace App\Enums;

enum LaneClosureReason: string
{
    // Short jobs: done in a set number of minutes, after which the lane
    // reopens by itself.
    case ReOil = 're_oil';
    case Maintenance = 'maintenance';

    // The lane is broken. It is closed at once and stays closed until staff
    // reopen it.
    case Repair = 'repair';

    /**
     * Whether the lane reopens by itself once the job's time is up.
     */
    public function isShort(): bool
    {
        return $this !== self::Repair;
    }

    public function label(): string
    {
        return match ($this) {
            self::ReOil => 'Re-oil',
            self::Maintenance => 'Maintenance',
            self::Repair => 'Repair',
        };
    }
}
