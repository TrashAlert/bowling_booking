<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WaitlistEntry extends Model
{
    protected $fillable = [
        'customer_id',
        'minutes',
        'booking_id',
        'party_size',
        'status',
        'called_at',
        'seated_at',
    ];

    // Never send the secret token to the browser by accident.
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'party_size' => 'integer',
            'status' => WaitlistStatus::class,
            'called_at' => 'datetime',
            'seated_at' => 'datetime',
        ];
    }

    // Give every new entry a random, hard-to-guess token automatically.
    protected static function booted(): void
    {
        static::creating(function (WaitlistEntry $entry) {
            $entry->token ??= Str::random(40);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // Everyone still in line, first come first served.
    public function scopeInLine(Builder $query): void
    {
        $query->whereIn('status', WaitlistStatus::inLine())
            ->orderBy('created_at')
            ->orderBy('id');
    }

    // 1 = next in line. Null if this entry is no longer in line.
    public function position(): ?int
    {
        if (! in_array($this->status->value, WaitlistStatus::inLine(), true)) {
            return null;
        }

        $ahead = static::query()
            ->whereIn('status', WaitlistStatus::inLine())
            ->where(function (Builder $q) {
                $q->where('created_at', '<', $this->created_at)
                    ->orWhere(function (Builder $q) {
                        $q->where('created_at', $this->created_at)
                            ->where('id', '<', $this->id);
                    });
            })
            ->count();

        return $ahead + 1;
    }
}
