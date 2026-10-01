<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $fillable = [
        'customer_id',
        'minutes',
        'party_size',
        'source',
        'status',
        'total_cents',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'party_size' => 'integer',
            'total_cents' => 'integer',
            'source' => BookingSource::class,
            'status' => BookingStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // One booking can take several lanes, e.g. a party of 12 on two lanes.
    public function allocations(): HasMany
    {
        return $this->hasMany(LaneAllocation::class);
    }

    // The waitlist entry this booking came from, if it was a walk-in.
    public function waitlistEntry(): HasOne
    {
        return $this->hasOne(WaitlistEntry::class);
    }
}
