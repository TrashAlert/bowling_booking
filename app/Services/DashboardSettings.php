<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Which parts of the staff dashboard an admin has turned on, under Settings.
 * It is the same for everyone. A part nobody has turned off is shown, so a
 * part added later starts out shown.
 */
class DashboardSettings
{
    /**
     * Every part that can be turned off, in the order the dashboard shows
     * them.
     *
     * @var list<string>
     */
    public const PARTS = [
        'rightNow',
        'attention',
        'totals',
        'sessionsByDay',
        'sessionsByHour',
        'busiestTimes',
        'closedTime',
    ];

    /**
     * The parts under "How the venue is doing". While all of them are off,
     * none of the analytics is worked out.
     *
     * @var list<string>
     */
    public const ANALYTICS_PARTS = ['totals', 'sessionsByDay', 'sessionsByHour', 'busiestTimes', 'closedTime'];

    private const HIDDEN = 'dashboard.hidden_parts';

    /**
     * Whether each part is shown, keyed by part.
     *
     * @return array<string, bool>
     */
    public function shown(): array
    {
        $hidden = Setting::query()->where('key', self::HIDDEN)->value('value') ?? [];

        return array_combine(self::PARTS, array_map(fn (string $part) => ! in_array($part, $hidden, true), self::PARTS));
    }

    /**
     * Turn parts on or off. A part left out keeps showing.
     *
     * @param  array<string, bool>  $shown
     */
    public function save(array $shown): void
    {
        $hidden = array_values(array_filter(self::PARTS, fn (string $part) => ($shown[$part] ?? true) === false));

        Setting::updateOrCreate(['key' => self::HIDDEN], ['value' => $hidden]);
    }

    /**
     * Whether any part of the analytics is shown.
     *
     * @param  array<string, bool>  $shown
     */
    public function showsAnalytics(array $shown): bool
    {
        return array_filter(self::ANALYTICS_PARTS, fn (string $part) => $shown[$part]) !== [];
    }
}
