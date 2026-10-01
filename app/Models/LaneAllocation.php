<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaneAllocation extends Model
{
    protected $fillable = [
        'lane_id',
        'booking_id',
        'starts_at',
        'ends_at',
        'status',
        'held_until',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'held_until' => 'datetime',
            'status' => AllocationStatus::class,
        ];
    }

    // Includes lanes that have since been removed, so history stays readable.
    public function lane(): BelongsTo
    {
        return $this->belongsTo(Lane::class)->withTrashed();
    }

    // Empty for league nights and maintenance blocks.
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // Allocations that still take up their lane (anything not released).
    public function scopeOccupying(Builder $query): void
    {
        $query->where('status', '!=', AllocationStatus::Released->value);
    }

    // Allocations that overlap the period from $start to $end.
    public function scopeOverlapping(Builder $query, DateTimeInterface|string $start, DateTimeInterface|string $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}
