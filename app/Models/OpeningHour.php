<?php

namespace App\Models;

use Database\Factories\OpeningHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * When the venue is open on one day of the week, as clock times in its own
 * time zone. Both times empty means closed all day.
 *
 * @property int $weekday
 * @property string|null $opens_at
 * @property string|null $closes_at
 */
class OpeningHour extends Model
{
    /** @use HasFactory<OpeningHourFactory> */
    use HasFactory;

    protected $fillable = ['weekday', 'opens_at', 'closes_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function isClosed(): bool
    {
        return $this->opens_at === null || $this->closes_at === null;
    }
}
