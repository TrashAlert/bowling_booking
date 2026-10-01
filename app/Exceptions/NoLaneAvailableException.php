<?php

namespace App\Exceptions;

use RuntimeException;

class NoLaneAvailableException extends RuntimeException
{
    /**
     * $laneNumber is set when a specific lane that was asked for isn't free.
     */
    public function __construct(
        public readonly int $needed,
        public readonly int $found,
        public readonly ?int $laneNumber = null,
    ) {
        parent::__construct($laneNumber === null
            ? "Needed {$needed} lane(s) but only {$found} were free."
            : "Lane {$laneNumber} is not free for that time.");
    }
}
