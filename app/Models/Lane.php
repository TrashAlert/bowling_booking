<?php

namespace App\Models;

use App\Enums\LaneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lane extends Model
{
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
