<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One choice an admin has made about how the venue runs, kept by name. A
 * setting nobody has saved yet has no row, and counts as its default.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
