<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The words on the front page that an admin can change under Settings: the
 * line under the business name, and the title, description and button of
 * the waitlist and reservation cards. A text left empty keeps its default.
 * Also the cover photo shown behind the business name, if one is uploaded,
 * and an announcement shown across the top of every customer page.
 */
class FrontPage
{
    private const COVER_PATH = 'front_page.cover_path';

    private const SHOW_OPENING_HOURS = 'front_page.show_opening_hours';

    private const ANNOUNCEMENT = 'front_page.announcement';

    private const ANNOUNCEMENT_UNTIL = 'front_page.announcement_until';

    // The most characters an announcement can have.
    public const ANNOUNCEMENT_LENGTH = 200;

    // Kept out of the public folder and served by the app, like the logo.
    private const DISK = 'local';

    /**
     * Each text, by its name on the settings form, with its default and the
     * most characters it can have.
     *
     * @var array<string, array{default: string, max: int}>
     */
    public const TEXTS = [
        'tagline' => ['default' => 'Come and bowl. Walk in, or book a lane ahead.', 'max' => 120],
        'waitlist_title' => ['default' => 'Join the waitlist', 'max' => 40],
        'waitlist_text' => ['default' => 'Walking in? Add your group to the line and we will call you when a lane is ready.', 'max' => 200],
        'waitlist_button' => ['default' => 'Join the waitlist', 'max' => 30],
        'reserve_title' => ['default' => 'Reserve a lane', 'max' => 40],
        'reserve_text' => ['default' => 'Planning ahead? Pick a date and time and we will keep a lane for you.', 'max' => 200],
        'reserve_button' => ['default' => 'Make a reservation', 'max' => 30],
    ];

    /**
     * What an admin has written, by name, with null for a text left at its
     * default.
     *
     * @return array<string, string|null>
     */
    public function saved(): array
    {
        $saved = Setting::query()
            ->whereIn('key', array_map($this->key(...), array_keys(self::TEXTS)))
            ->pluck('value', 'key');

        return collect(self::TEXTS)
            ->map(fn (array $text, string $name) => $saved->get($this->key($name)))
            ->all();
    }

    /**
     * Every text as the front page shows it, keyed in camel case for the
     * page, e.g. "waitlistTitle".
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        return collect($this->saved())
            ->mapWithKeys(fn (?string $text, string $name) => [Str::camel($name) => $text ?? self::TEXTS[$name]['default']])
            ->all();
    }

    /**
     * Save the texts given by name. One left empty goes back to its default;
     * one not given is left as it is.
     *
     * @param  array<string, string|null>  $texts
     */
    public function save(array $texts): void
    {
        foreach (array_intersect_key($texts, self::TEXTS) as $name => $text) {
            blank($text)
                ? Setting::forget($this->key($name))
                : Setting::updateOrCreate(['key' => $this->key($name)], ['value' => trim($text)]);
        }
    }

    /**
     * Whether the week's opening hours are listed on the front page. On until
     * an admin turns it off; nothing is listed while no hours are set.
     */
    public function showsOpeningHours(): bool
    {
        return Setting::query()->where('key', self::SHOW_OPENING_HOURS)->value('value') ?? true;
    }

    /**
     * List the opening hours on the front page, or stop listing them.
     */
    public function showOpeningHours(bool $show): void
    {
        Setting::updateOrCreate(['key' => self::SHOW_OPENING_HOURS], ['value' => $show]);
    }

    /**
     * The announcement an admin wrote, and the last day to show it as a
     * calendar date on the venue's clock ("2026-04-12"), each null when not
     * set. It may have ended already.
     *
     * @return array{message: string|null, until: string|null}
     */
    public function announcement(): array
    {
        $saved = Setting::query()
            ->whereIn('key', [self::ANNOUNCEMENT, self::ANNOUNCEMENT_UNTIL])
            ->pluck('value', 'key');

        return ['message' => $saved->get(self::ANNOUNCEMENT), 'until' => $saved->get(self::ANNOUNCEMENT_UNTIL)];
    }

    /**
     * The announcement customers see now, or null when there is none or its
     * last day has passed on the venue's clock.
     */
    public function currentAnnouncement(): ?string
    {
        ['message' => $message, 'until' => $until] = $this->announcement();

        if ($until !== null && CarbonImmutable::now(config('bowling.timezone'))->toDateString() > $until) {
            return null;
        }

        return $message;
    }

    /**
     * Put up an announcement, shown through $until if given, or take it down
     * by giving no message.
     */
    public function announce(?string $message, ?string $until): void
    {
        if (blank($message)) {
            Setting::forget(self::ANNOUNCEMENT, self::ANNOUNCEMENT_UNTIL);

            return;
        }

        Setting::updateOrCreate(['key' => self::ANNOUNCEMENT], ['value' => trim($message)]);

        blank($until)
            ? Setting::forget(self::ANNOUNCEMENT_UNTIL)
            : Setting::updateOrCreate(['key' => self::ANNOUNCEMENT_UNTIL], ['value' => $until]);
    }

    /**
     * Where the cover photo is kept on disk, or null when there is none.
     */
    public function coverPath(): ?string
    {
        return Setting::query()->where('key', self::COVER_PATH)->value('value');
    }

    /**
     * The address pages load the cover photo from, or null when there is
     * none. It changes with every new photo, so browsers never show a stale
     * one.
     */
    public function coverUrl(): ?string
    {
        $path = $this->coverPath();

        return $path === null
            ? null
            : route('front-page.cover', ['v' => substr(md5($path), 0, 8)], absolute: false);
    }

    /**
     * Take a newly uploaded cover photo into use and throw the old one away.
     */
    public function useCover(UploadedFile $photo): void
    {
        $old = $this->coverPath();

        Setting::updateOrCreate(['key' => self::COVER_PATH], ['value' => $photo->store('front-page', self::DISK)]);

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    /**
     * Go back to having no cover photo.
     */
    public function removeCover(): void
    {
        $old = $this->coverPath();

        Setting::forget(self::COVER_PATH);

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    /**
     * The disk the cover photo is kept on.
     */
    public function disk(): string
    {
        return self::DISK;
    }

    private function key(string $name): string
    {
        return "front_page.{$name}";
    }
}
