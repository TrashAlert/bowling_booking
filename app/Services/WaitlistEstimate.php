<?php

namespace App\Services;

use App\Enums\LaneStatus;
use App\Enums\WaitlistStatus;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A rough guess of how long a party will wait for a lane.
 *
 * It plays the line forward: every lane is busy for as long as what is booked
 * on it says, and each waiting party in turn takes the first lane that is
 * free for its whole session, the same rule parties are called by. It is only
 * an estimate. It assumes everyone plays their full time and checks in at
 * once, and it leaves out lanes that are out of order.
 */
class WaitlistEstimate
{
    // How far ahead the estimate looks for a free lane.
    private const LOOKAHEAD_HOURS = 48;

    public function __construct(private BookingService $bookings) {}

    /**
     * How many minutes a party of one lane would wait if it joined the line
     * now, for each session length on offer. Null where no lane can be found.
     *
     * @return array<int, int|null>
     */
    public function forNewParty(): array
    {
        $now = CarbonImmutable::now();
        $busy = $this->seatInTurn($this->busyTimes($now), $this->waiting(), $now);

        $step = config('bowling.session_step_minutes');

        return collect(range($step, config('bowling.max_session_minutes'), $step))
            ->mapWithKeys(fn (int $minutes) => [
                $minutes => $this->minutesUntil($this->firstOpening($busy, 1, $minutes, $now), $now),
            ])
            ->all();
    }

    /**
     * How many minutes until a waiting party is likely to be called. Null if
     * it isn't waiting any more, or no lane can be found for it.
     */
    public function forEntry(WaitlistEntry $entry): ?int
    {
        if ($entry->status !== WaitlistStatus::Waiting) {
            return null;
        }

        $now = CarbonImmutable::now();
        $ahead = $this->waiting()->takeUntil(fn (WaitlistEntry $other) => $other->is($entry));
        $busy = $this->seatInTurn($this->busyTimes($now), $ahead, $now);

        return $this->minutesUntil(
            $this->firstOpening($busy, $this->bookings->lanesNeeded($entry->party_size), $entry->minutes, $now),
            $now,
        );
    }

    /**
     * How many lanes are free right now once every waiting party has been
     * given the lane it is about to be called to. A lane counts while it is
     * open and nothing is on it or held for anyone.
     */
    public function lanesFreeNow(): int
    {
        $now = CarbonImmutable::now();
        $busy = $this->seatInTurn($this->busyTimes($now), $this->waiting(), $now);

        return count($this->freeLanes($busy, $now->getTimestamp(), $now->addMinute()->getTimestamp()));
    }

    /**
     * The parties waiting to be called, first come first served.
     *
     * @return Collection<int, WaitlistEntry>
     */
    private function waiting(): Collection
    {
        return WaitlistEntry::query()
            ->where('status', WaitlistStatus::Waiting->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * When each open lane is taken, as [start, end] Unix times keyed by lane
     * id, lowest lane number first. A lane with nothing on it has an empty list.
     *
     * @return array<int, list<array{int, int}>>
     */
    private function busyTimes(CarbonImmutable $now): array
    {
        return Lane::query()
            ->where('status', LaneStatus::Open->value)
            ->orderBy('number')
            ->with(['allocations' => function ($query) use ($now) {
                $query->occupying()
                    ->where('ends_at', '>', $now)
                    ->where('starts_at', '<', $now->addHours(self::LOOKAHEAD_HOURS));
            }])
            ->get()
            ->mapWithKeys(fn (Lane $lane) => [
                $lane->id => $lane->allocations
                    ->map(fn (LaneAllocation $allocation) => [
                        $allocation->starts_at->getTimestamp(),
                        $allocation->ends_at->getTimestamp(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Give each party in turn the first lanes free for its whole session and
     * return the lanes' busy times with those sessions added. A party that
     * fits nowhere is passed over, as it is when parties are called.
     *
     * @param  array<int, list<array{int, int}>>  $busy
     * @param  Collection<int, WaitlistEntry>  $parties
     * @return array<int, list<array{int, int}>>
     */
    private function seatInTurn(array $busy, Collection $parties, CarbonImmutable $now): array
    {
        foreach ($parties as $party) {
            $opening = $this->firstOpening($busy, $this->bookings->lanesNeeded($party->party_size), $party->minutes, $now);

            if ($opening === null) {
                continue;
            }

            foreach ($opening['lanes'] as $laneId) {
                $busy[$laneId][] = [$opening['start'], $opening['start'] + $party->minutes * 60];
            }
        }

        return $busy;
    }

    /**
     * The earliest moment from now at which enough lanes are free for a
     * whole session, and which lanes those are. A lane can only become free
     * now or when something on a lane ends, so only those moments are tried.
     *
     * @param  array<int, list<array{int, int}>>  $busy
     * @return array{start: int, lanes: list<int>}|null
     */
    private function firstOpening(array $busy, int $lanesNeeded, int $minutes, CarbonImmutable $now): ?array
    {
        $from = $now->getTimestamp();
        $until = $now->addHours(self::LOOKAHEAD_HOURS)->getTimestamp();

        $moments = collect($busy)
            ->flatten(1)
            ->map(fn (array $period) => $period[1])
            ->filter(fn (int $end) => $end > $from && $end <= $until)
            ->push($from)
            ->unique()
            ->sort();

        foreach ($moments as $start) {
            $end = $start + $minutes * 60;

            $free = $this->freeLanes($busy, $start, $end);

            if (count($free) >= $lanesNeeded) {
                return ['start' => $start, 'lanes' => array_slice($free, 0, $lanesNeeded)];
            }
        }

        return null;
    }

    /**
     * The ids of the lanes with nothing on them from $start to $end, given
     * as Unix times, lowest lane number first.
     *
     * @param  array<int, list<array{int, int}>>  $busy
     * @return list<int>
     */
    private function freeLanes(array $busy, int $start, int $end): array
    {
        return array_keys(array_filter($busy, fn (array $periods) => collect($periods)
            ->doesntContain(fn (array $period) => $period[0] < $end && $period[1] > $start)));
    }

    /**
     * Whole minutes from now until an opening, rounded up.
     *
     * @param  array{start: int, lanes: list<int>}|null  $opening
     */
    private function minutesUntil(?array $opening, CarbonImmutable $now): ?int
    {
        return $opening === null
            ? null
            : (int) ceil(($opening['start'] - $now->getTimestamp()) / 60);
    }
}
