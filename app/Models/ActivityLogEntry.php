<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the activity log: who did what, and when. Lines are only ever
 * written by App\Services\ActivityLog, which chains each to the one before
 * it. The database refuses to change them, and only removes them once they
 * are more than 18 months old, so this model is for reading.
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $summary
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property string|null $ip_address
 * @property string|null $previous_hash
 * @property string $hash
 */
class ActivityLogEntry extends Model
{
    protected $table = 'activity_logs';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'changes' => 'array',
        ];
    }
}
