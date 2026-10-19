<?php

namespace App\Models;

use App\Services\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One choice an admin has made about how the venue runs, kept by name. A
 * setting nobody has saved yet has no row, and counts as its default.
 *
 * Every change is written to the activity log in the same transaction, so a
 * setting is never changed without a line saying so.
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

    /**
     * Log every change: a new setting, a new value, or one cleared.
     */
    protected static function booted(): void
    {
        static::created(fn (Setting $setting) => app(ActivityLog::class)->settingChanged($setting->key, null, $setting->value));
        static::updated(fn (Setting $setting) => app(ActivityLog::class)->settingChanged($setting->key, $setting->getOriginal('value'), $setting->value));
        static::deleted(fn (Setting $setting) => app(ActivityLog::class)->settingChanged($setting->key, $setting->value, null));
    }

    /**
     * Clear the given settings, so each goes back to its default. Each one is
     * removed on its own, so each removal is logged.
     */
    public static function forget(string ...$keys): void
    {
        static::query()->whereIn('key', $keys)->get()->each(fn (Setting $setting) => $setting->delete());
    }

    /**
     * Save the setting and its line in the activity log together.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /**
     * Remove the setting and write its line in the activity log together.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
    }
}
