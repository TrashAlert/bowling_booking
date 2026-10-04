<?php

namespace App\Models;

use App\Enums\BookingRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request for a reservation, sent from the public form. Staff
 * phone the customer and turn it into a reservation, or decline it. Nothing
 * is booked until then.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property int $party_size
 * @property int $minutes
 * @property CarbonImmutable $starts_at
 * @property string|null $contact_from
 * @property string|null $contact_until
 * @property string|null $notes
 * @property BookingRequestStatus $status
 * @property int|null $booking_id
 * @property int|null $handled_by
 * @property CarbonImmutable|null $handled_at
 * @property string|null $decline_reason
 */
class BookingRequest extends Model
{
    /** @use HasFactory<BookingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'party_size',
        'minutes',
        'starts_at',
        'contact_from',
        'contact_until',
        'notes',
        'status',
        'booking_id',
        'handled_by',
        'handled_at',
        'decline_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'minutes' => 'integer',
            'starts_at' => 'datetime',
            'handled_at' => 'datetime',
            'status' => BookingRequestStatus::class,
        ];
    }

    /**
     * The reservation the request became, once confirmed.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The staff member who confirmed or declined it.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Requests still to deal with: not handled, and their time is still to come.
     *
     * @param  Builder<BookingRequest>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', BookingRequestStatus::Pending->value)->where('starts_at', '>', now());
    }

    /**
     * Requests nobody dealt with before their time came.
     *
     * @param  Builder<BookingRequest>  $query
     */
    public function scopeMissed(Builder $query): void
    {
        $query->where('status', BookingRequestStatus::Pending->value)->where('starts_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->status === BookingRequestStatus::Pending;
    }
}
