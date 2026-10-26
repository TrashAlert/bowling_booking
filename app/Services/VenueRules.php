<?php

namespace App\Services;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * The booking rules an admin can change under Settings instead of in .env.
 *
 * Each rule still has its place in config/bowling.php, where the rest of
 * the app reads it. A rule an admin has saved is laid over that value when
 * the app starts, so nothing that reads the config needs to know where the
 * value came from. A rule nobody has saved keeps its .env value or default.
 */
class VenueRules
{
    /**
     * The rules that can be saved, by their name in config/bowling.php.
     *
     * @var list<string>
     */
    public const EDITABLE = [
        'waitlist_deposit_cents',
        'waitlist_checkin_minutes',
        'waitlist_max_per_device',
        'no_show_grace_minutes',
        'reservation_lead_minutes',
        'check_in_opens_minutes',
        'reservation_max_days_ahead',
        'max_players_per_lane',
        'max_session_minutes',
        'session_mode',
        'minutes_per_player_game',
        'max_games',
        'currency_symbol',
        'timezone',
    ];

    /**
     * Lay every saved rule over the config. Run once as the app starts.
     */
    public function applySaved(): void
    {
        $saved = Setting::query()
            ->whereIn('key', array_map($this->key(...), self::EDITABLE))
            ->pluck('value', 'key');

        foreach ($saved as $key => $value) {
            config([$key => $value]);
        }
    }

    /**
     * Save a rule, and use it from this moment on.
     *
     * @throws InvalidArgumentException when the rule is not one that can be saved.
     */
    public function save(string $rule, int|string $value): void
    {
        if (! in_array($rule, self::EDITABLE, true)) {
            throw new InvalidArgumentException("The rule [{$rule}] cannot be changed from the settings.");
        }

        Setting::updateOrCreate(['key' => $this->key($rule)], ['value' => $value]);

        config([$this->key($rule) => $value]);
    }

    /**
     * A rule's name as the config and the settings table know it.
     */
    private function key(string $rule): string
    {
        return "bowling.{$rule}";
    }
}
