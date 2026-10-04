<?php

namespace App\Services;

use App\Models\OpeningHour;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When the venue is open. Hours are set per day of the week, as clock times
 * in the venue's own time zone, and a day may run past midnight.
 *
 * Until any hours are saved the venue counts as always open, so nothing is
 * turned away before an admin has set them.
 */
class OpeningHours
{
    /**
     * The days of the week, Monday first, as Carbon numbers them.
     *
     * @var list<int>
     */
    public const WEEK = [1, 2, 3, 4, 5, 6, 0];

    /**
     * The venue's time zone, which opening hours are read in.
     */
    public function timezone(): string
    {
        return config('bowling.timezone');
    }

    /**
     * Whether any hours have been saved yet.
     */
    public function isSet(): bool
    {
        return OpeningHour::query()->exists();
    }

    /**
     * Every day of the week, Monday first, with its hours as "HH:MM", or
     * nulls when closed or not set.
     *
     * @return list<array{weekday: int, opens: string|null, closes: string|null}>
     */
    public function week(): array
    {
        $days = $this->days();

        return array_map(fn (int $weekday) => [
            'weekday' => $weekday,
            'opens' => $this->clock($days->get($weekday)?->opens_at),
            'closes' => $this->clock($days->get($weekday)?->closes_at),
        ], self::WEEK);
    }

    /**
     * Replace the hours. Each day is keyed by its number, 0 for Sunday, and
     * gives "HH:MM" times, or null when the venue is closed that day.
     *
     * @param  array<int, array{opens: string, closes: string}|null>  $days
     */
    public function save(array $days): void
    {
        DB::transaction(function () use ($days) {
            foreach (self::WEEK as $weekday) {
                $hours = $days[$weekday] ?? null;

                OpeningHour::updateOrCreate(['weekday' => $weekday], [
                    'opens_at' => $hours['opens'] ?? null,
                    'closes_at' => $hours['closes'] ?? null,
                ]);
            }
        });
    }

    public function isOpenAt(CarbonInterface $moment): bool
    {
        if (! $this->isSet()) {
            return true;
        }

        return $this->openingAround($moment) !== null;
    }

    /**
     * When the venue next opens after $moment, or null if it is open then,
     * no hours are set, or it is closed every day.
     */
    public function opensNext(CarbonInterface $moment): ?CarbonImmutable
    {
        if (! $this->isSet() || $this->isOpenAt($moment)) {
            return null;
        }

        $local = $this->local($moment);

        return $this->openings($local)
            ->first(fn (array $opening) => $opening['opens'] > $local)['opens'] ?? null;
    }

    /**
     * When the opening $moment falls in ends, or null if the venue is closed
     * then or no hours are set.
     */
    public function closesNext(CarbonInterface $moment): ?CarbonImmutable
    {
        if (! $this->isSet()) {
            return null;
        }

        return $this->openingAround($moment)['closes'] ?? null;
    }

    /**
     * Whether the venue is open right now and when that changes, with times
     * as ISO 8601 strings in UTC. Both times are null when no hours are set.
     *
     * @return array{isOpen: bool, opensAt: string|null, closesAt: string|null}
     */
    public function status(): array
    {
        $now = now();

        return [
            'isOpen' => $this->isOpenAt($now),
            'opensAt' => $this->opensNext($now)?->utc()->toIso8601String(),
            'closesAt' => $this->closesNext($now)?->utc()->toIso8601String(),
        ];
    }

    /**
     * What to tell a customer turned away because the venue is closed at
     * $moment, with when it opens again in the venue's time zone.
     */
    public function closedNotice(CarbonInterface $moment): string
    {
        $opens = $this->opensNext($moment);

        if ($opens === null) {
            return __('We are closed right now.');
        }

        $today = $this->local($moment)->startOfDay();

        $day = match (true) {
            $opens->isSameDay($today) => __('today'),
            $opens->isSameDay($today->addDay()) => __('tomorrow'),
            default => __('on :day', ['day' => $opens->format('l')]),
        };

        return __('We are closed right now. We open again :day at :time.', [
            'day' => $day,
            'time' => $opens->format('g:i A'),
        ]);
    }

    /**
     * The opening $moment falls in, if any.
     *
     * @return array{opens: CarbonImmutable, closes: CarbonImmutable}|null
     */
    private function openingAround(CarbonInterface $moment): ?array
    {
        $local = $this->local($moment);

        return $this->openings($local)
            ->first(fn (array $opening) => $opening['opens'] <= $local && $local < $opening['closes']);
    }

    /**
     * Every opening from the day before $local to a week after it, earliest
     * first, as moments in the venue's time zone. The day before is there
     * for a night that runs past midnight into $local's day.
     *
     * @return Collection<int, array{opens: CarbonImmutable, closes: CarbonImmutable}>
     */
    private function openings(CarbonImmutable $local): Collection
    {
        $days = $this->days();

        return collect(range(-1, 7))
            ->map(fn (int $offset) => $local->startOfDay()->addDays($offset))
            ->map(function (CarbonImmutable $date) use ($days) {
                $hours = $days->get($date->dayOfWeek);

                if ($hours === null || $hours->isClosed()) {
                    return null;
                }

                $opens = $date->setTimeFromTimeString($hours->opens_at);
                $closes = $date->setTimeFromTimeString($hours->closes_at);

                return ['opens' => $opens, 'closes' => $closes <= $opens ? $closes->addDay() : $closes];
            })
            ->filter()
            ->values();
    }

    /**
     * The saved hours keyed by day of the week.
     *
     * @return Collection<int, OpeningHour>
     */
    private function days(): Collection
    {
        return OpeningHour::query()->get()->keyBy('weekday');
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone());
    }

    /**
     * A time from the database ("10:00:00") as the settings page shows it.
     */
    private function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
