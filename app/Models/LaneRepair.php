<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LaneRepairFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spell of a lane being out of order for repair, from when staff closed
 * it to when they opened it again. Kept so the time lanes spend out of order
 * can be counted afterwards; the lane itself only knows whether it is out of
 * order now.
 *
 * @property int $id
 * @property int $lane_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
class LaneRepair extends Model
{
    /** @use HasFactory<LaneRepairFactory> */
    use HasFactory;

    protected $fillable = ['lane_id', 'started_at', 'ended_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The lane that was out of order, even if it has since been removed.
     *
     * @return BelongsTo<Lane, $this>
     */
    public function lane(): BelongsTo
    {
        return $this->belongsTo(Lane::class)->withTrashed();
    }

    /**
     * Repairs still going on.
     *
     * @param  Builder<LaneRepair>  $query
     */
    public function scopeOngoing(Builder $query): void
    {
        $query->whereNull('ended_at');
    }
}
