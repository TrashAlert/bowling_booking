<?php

namespace App\Models;

use App\Enums\LaneStatus;
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

    protected $fillable = ['number', 'has_bumpers', 'status'];

    protected function casts(): array
    {
        return [
            'has_bumpers' => 'boolean',
            'status' => LaneStatus::class,
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
}
