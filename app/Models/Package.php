<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['name', 'minutes', 'price_cents', 'max_players', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
