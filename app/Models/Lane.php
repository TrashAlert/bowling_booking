<?php

namespace App\Models;

use App\Enums\LaneClosureReason;
use App\Enums\LaneStatus;
use Carbon\CarbonInterface;
use Database\Factories\LaneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lane extends Model
{
    /** @use HasFactory<LaneFactory> */
    use HasFactory;

    // A removed lane is only hidden, so its past bookings keep their lane.
    use SoftDeletes;

    protected $fillable = ['number', 'has_bumpers', 'status', 'closed_reason', 'closed_until'];

    protected function casts(): array
    {
        return [
            'has_bumpers' => 'boolean',
            'status' => LaneStatus::class,
            'closed_reason' => LaneClosureReason::class,
            'closed_until' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LaneAllocation::class);
    }

    public function isOpen(): bool
    {
        return $this->status === LaneStatus::Open;
    }

    /**
     * Whether a reservation that starts at $startsAt has to be moved off
     * this lane because it is out of order.
     *
     * closed_until is only an estimate of when the lane will be back. A
     * reservation after it is left alone, unless the estimate has already
     * passed with the lane still closed: then nobody knows when it reopens.
     */
    public function needsMovingAt(CarbonInterface $startsAt): bool
    {
        if ($this->isOpen()) {
            return false;
        }

        if ($this->closed_until === null || $this->closed_until <= now()) {
            return true;
        }

        return $startsAt < $this->closed_until;
    }
}
