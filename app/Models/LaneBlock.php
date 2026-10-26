<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LaneBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lanes kept for something other than a booking, such as a league night or
 * a private event, from a start to an end time, with a note saying what for.
 * Each lane it covers has a row in lane_allocations, which is what keeps the
 * lane taken. Those rows start a little before the block itself, so the lane
 * is empty on time, as a reservation's lanes are.
 *
 * @property int $id
 * @property string $note
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int|null $created_by
 */
class LaneBlock extends Model
{
    /** @use HasFactory<LaneBlockFactory> */
    use HasFactory;

    protected $fillable = ['note', 'starts_at', 'ends_at', 'created_by'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The rows that keep each blocked lane taken.
     *
     * @return HasMany<LaneAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LaneAllocation::class);
    }

    /**
     * The staff member who blocked the lanes.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
