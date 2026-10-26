<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\LaneBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blocking lanes for something other than a booking, such as a league night
 * or a private event, and freeing them again.
 *
 * A blocked lane is taken like a reserved one: from the lead time before the
 * start (see LaneAvailability::closesAt), so it is empty on time, until the
 * end. Nobody is called to it and nothing else can be booked on it then.
 */
class LaneBlocks
{
    // PostgreSQL's error code when our no-overlap rule blocks an insert.
    private const OVERLAP_ERROR = '23P01';

    // The most weeks in a row one block can be repeated for.
    public const MAX_WEEKS = 13;

    public function __construct(
        private LaneAvailability $availability,
        private ActivityLog $log,
    ) {}

    /**
     * Block the chosen lanes from $startsAt for $minutes, with a note saying
     * what for, and the same again on the same weekday and clock time for
     * each further week up to $weeks. Every lane is blocked in every week,
     * or nothing is.
     *
     * @param  Collection<int, Lane>  $lanes
     * @return Collection<int, LaneBlock> one block per week, earliest first.
     *
     * @throws InvalidStateException naming the first lane and day that is closed or isn't free.
     */
    public function block(Collection $lanes, CarbonImmutable $startsAt, int $minutes, string $note, int $weeks = 1, ?User $by = null): Collection
    {
        if ($lanes->isEmpty()) {
            throw new InvalidArgumentException('A block needs at least one lane.');
        }

        // Each week is the same clock time on the venue's clock.
        $local = $startsAt->setTimezone(config('bowling.timezone'));

        return DB::transaction(fn () => collect(range(0, $weeks - 1))
            ->map(fn (int $week) => $this->blockOnce($lanes, $local->addWeeks($week)->utc(), $minutes, $note, $by)));
    }

    /**
     * Free a block's lanes. A block under way ends now; one still to come is
     * dropped.
     *
     * @throws InvalidStateException if the block is already over.
     */
    public function remove(LaneBlock $block): void
    {
        DB::transaction(function () use ($block) {
            $block = LaneBlock::query()->lockForUpdate()->findOrFail($block->id);

            if ($block->ends_at <= now()) {
                throw new InvalidStateException(__('This block is already over.'));
            }

            $lanes = $this->laneNumbers($block);
            $underWay = $block->starts_at <= now();

            // Its rows in lane_allocations go with it, which frees the lanes.
            $block->delete();

            $this->log->record('lane.unblocked', sprintf(
                '%s the %s block on %s, %s to %s.',
                $underWay ? 'Ended' : 'Removed',
                $block->note,
                $this->log->lanes($lanes),
                $this->log->when($block->starts_at),
                $this->log->time($block->ends_at),
            ), $block);
        });
    }

    /**
     * The blocks that start from $from up to (not including) $to, earliest
     * first, as the Reservations page lists them. Times are ISO 8601 in UTC.
     *
     * @return list<array{id: int, note: string, startsAt: string, endsAt: string, keptClearFrom: string, lanes: list<array{id: int, number: int}>, createdBy: string|null}>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        return LaneBlock::query()
            ->where('starts_at', '>=', $from->toImmutable()->utc())
            ->where('starts_at', '<', $to->toImmutable()->utc())
            ->with(['allocations.lane', 'creator'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (LaneBlock $block) => [
                'id' => $block->id,
                'note' => $block->note,
                'startsAt' => $block->starts_at->toIso8601String(),
                'endsAt' => $block->ends_at->toIso8601String(),
                // When its lanes stop taking anyone, ahead of the start.
                'keptClearFrom' => ($block->allocations->min('starts_at') ?? $block->starts_at)->toIso8601String(),
                'lanes' => $block->allocations
                    ->sortBy('lane.number')
                    ->map(fn (LaneAllocation $allocation) => ['id' => $allocation->lane_id, 'number' => $allocation->lane->number])
                    ->values()
                    ->all(),
                'createdBy' => $block->creator?->name,
            ])
            ->all();
    }

    /**
     * Block the lanes once, from $startsAt for $minutes.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws InvalidStateException naming the first lane that is closed or isn't free.
     */
    private function blockOnce(Collection $lanes, CarbonImmutable $startsAt, int $minutes, string $note, ?User $by): LaneBlock
    {
        $endsAt = $startsAt->addMinutes($minutes);
        $closesAt = $this->availability->closesAt($startsAt);

        $block = LaneBlock::create([
            'note' => $note,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by' => $by?->id,
        ]);

        foreach ($lanes as $lane) {
            if (! $lane->isOpen()) {
                $this->failLane($lane, $startsAt);
            }

            try {
                // A savepoint, so a refused insert leaves the outer transaction usable.
                DB::transaction(fn () => $block->allocations()->create([
                    'lane_id' => $lane->id,
                    'starts_at' => $closesAt,
                    'ends_at' => $endsAt,
                    'status' => AllocationStatus::Active,
                    'note' => $note,
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                    $this->failLane($lane, $startsAt);
                }

                throw $e;
            }
        }

        $this->log->record('lane.blocked', sprintf(
            'Blocked %s for %s, %s to %s.',
            $this->log->lanes($lanes->pluck('number')),
            $note,
            $this->log->when($startsAt),
            $this->log->time($endsAt),
        ), $block);

        return $block;
    }

    /**
     * @throws InvalidStateException
     */
    private function failLane(Lane $lane, CarbonImmutable $startsAt): never
    {
        throw new InvalidStateException(__('Lane :number is closed or not free on :day. Pick other lanes or another time.', [
            'number' => $lane->number,
            'day' => $this->log->when($startsAt),
        ]));
    }

    /**
     * The numbers of the lanes a block is on.
     *
     * @return Collection<int, int>
     */
    private function laneNumbers(LaneBlock $block): Collection
    {
        return $block->allocations()->with('lane')->get()->pluck('lane.number');
    }
}
