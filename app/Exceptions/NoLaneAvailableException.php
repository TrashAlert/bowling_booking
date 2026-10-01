<?php

namespace App\Exceptions;

use RuntimeException;

class NoLaneAvailableException extends RuntimeException
{
    public function __construct(
        public readonly int $needed,
        public readonly int $found,
    ) {
        parent::__construct("Needed {$needed} lane(s) but only {$found} were free.");
    }
}
