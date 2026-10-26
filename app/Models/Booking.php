<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Builder;
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
        'games',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'party_size' => 'integer',
            'total_cents' => 'integer',
            'games' => 'integer',
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

    // The rows that keep a reservation's lanes empty in the hour before it starts.
    public function closures(): HasMany
    {
        return $this->hasMany(LaneAllocation::class, 'closed_for_booking_id');
    }

    // The waitlist entry this booking came from, if it was a walk-in.
    public function waitlistEntry(): HasOne
    {
        return $this->hasOne(WaitlistEntry::class);
    }

    /**
     * Bookings that can still be put on other lanes: still to check in, or
     * checked in early with none of their lane time begun.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeMovable(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('status', BookingStatus::Confirmed->value)
            ->orWhere(fn (Builder $query) => $query
                ->where('status', BookingStatus::CheckedIn->value)
                ->whereDoesntHave('allocations', fn (Builder $query) => $query->occupying()->where('starts_at', '<=', now()))));
    }

    /**
     * Whether the booking can still be put on other lanes, by the same rule
     * as scopeMovable(), read from the allocations already loaded.
     */
    public function isMovable(): bool
    {
        return match ($this->status) {
            BookingStatus::Confirmed => true,
            BookingStatus::CheckedIn => $this->allocations->isNotEmpty() && $this->allocations->min('starts_at') > now(),
            default => false,
        };
    }
}
