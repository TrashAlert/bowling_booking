<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    protected $fillable = ['name', 'minutes', 'price_cents', 'max_players', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
