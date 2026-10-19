<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\LaneClosureReason;
use App\Enums\LaneStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\WaitlistEntry;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closing lanes and opening them again.
 *
 * A short job (re-oiling, maintenance) is a block on the lane for a set
 * number of minutes. It takes the lane like any booking would, so nothing
 * else can be put there, and it ends by itself. A repair marks the lane out
 * of order until staff reopen it; reservations on it then have to be moved,
 * and a party that was called to it goes back in the line.
 */
class LaneClosures
{
    // PostgreSQL's error code when our no-overlap rule blocks a change.
    private const OVERLAP_ERROR = '23P01';

    public function __construct(private ActivityLog $log) {}

    /**
     * Close a lane for a short job. It starts now if the lane is free, and
     * otherwise when whatever is on the lane now has finished.
     *
     * @throws InvalidStateException if the lane is already closed, or isn't free for that long then.
     */
    public function closeForWork(Lane $lane, LaneClosureReason $reason, int $minutes): LaneAllocation
    {
        return DB::transaction(function () use ($lane, $reason, $minutes) {
            $lane = Lane::query()->lockForUpdate()->findOrFail($lane->id);

            if (! $lane->isOpen() || $this->pending($lane)->exists()) {
                throw new InvalidStateException(__('Lane :number is already closed or has a closure coming up.', ['number' => $lane->number]));
            }

            $onTheLaneNow = $lane->allocations()
                ->occupying()
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->first();

            $startsAt = $onTheLaneNow?->ends_at ?? now()->startOfSecond();

            try {
                // A savepoint, so a refused insert leaves the outer transaction usable.
                $closure = DB::transaction(fn () => LaneAllocation::create([
                    'lane_id' => $lane->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($minutes),
                    'status' => AllocationStatus::Active,
                    'closure_reason' => $reason,
                    'note' => $reason->label(),
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                    throw new InvalidStateException(__('Lane :number isn\'t free for :time then. Pick a shorter time or close it later.', [
                        'number' => $lane->number,
                        'time' => $this->spell($minutes),
                    ]));
                }

                throw $e;
            }

            $this->log->record('lane.closed_for_work', sprintf(
                'Closed lane %d for %s, %s, from %s to %s.',
                $lane->number,
                mb_strtolower($reason->label()),
                $this->spell($minutes),
                $this->log->time($closure->starts_at),
                $this->log->time($closure->ends_at),
            ), $lane);

            return $closure;
        });
    }

    /**
     * Mark a lane out of order for repair, from now until staff reopen it.
     * $days is only an estimate of when it will be back. A short closure
     * that was under way or coming up on the lane is dropped.
     *
     * A party playing on the lane finishes its session. A party that was
     * only called to it has nowhere to check in, so its hold is dropped and
     * it goes back in the line where it was, to be called to the next lane.
     *
     * The repair is logged from now. A lane already out of order keeps the
     * repair it has, with the new estimate.
     */
    public function closeForRepair(Lane $lane, ?int $days = null): Lane
    {
        return DB::transaction(function () use ($lane, $days) {
            $lane = Lane::query()->lockForUpdate()->findOrFail($lane->id);

            $this->endPending($lane);

            $returned = $this->partiesCalledTo($lane)->filter(fn (WaitlistEntry $entry) => $this->returnToLine($entry));

            $alreadyClosed = $lane->repairs()->ongoing()->exists();

            if (! $alreadyClosed) {
                $lane->repairs()->create(['started_at' => now()->startOfSecond()]);
            }

            $lane->update([
                'status' => LaneStatus::OutOfOrder,
                'closed_reason' => LaneClosureReason::Repair,
                'closed_until' => $days === null ? null : now()->addDays($days),
            ]);

            // Written last, once every change is made.
            $this->log->record('lane.closed_for_repair', sprintf(
                '%s lane %d for repair, %s.',
                $alreadyClosed ? 'Gave a new estimate for' : 'Closed',
                $lane->number,
                $days === null ? 'until reopened' : 'expected back in about '.trans_choice(':count day|:count days', $days),
            ), $lane);

            foreach ($returned as $entry) {
                $this->log->record('waitlist.returned', sprintf(
                    'Put %s back in the line: lane %d, which it was called to, closed for repair.',
                    $this->log->party($entry->customer->name, $entry->party_size),
                    $lane->number,
                ), $entry);
            }

            return $lane;
        });
    }

    /**
     * Give a short closure more time, when the job is taking longer.
     *
     * @throws InvalidStateException if the lane has no short closure, or is booked too soon after it.
     */
    public function addTime(Lane $lane, int $minutes): LaneAllocation
    {
        return DB::transaction(function () use ($lane, $minutes) {
            $closure = $this->pending($lane)->lockForUpdate()->first();

            if ($closure === null) {
                throw new InvalidStateException(__('Lane :number has no closure to add time to.', ['number' => $lane->number]));
            }

            try {
                // A savepoint, so a refused update leaves the outer transaction usable.
                DB::transaction(fn () => $closure->update(['ends_at' => $closure->ends_at->addMinutes($minutes)]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::OVERLAP_ERROR) {
                    throw new InvalidStateException(__('Lane :number is booked too soon after to add :time.', [
                        'number' => $lane->number,
                        'time' => $this->spell($minutes),
                    ]));
                }

                throw $e;
            }

            $this->log->record('lane.closure_extended', sprintf(
                'Kept lane %d closed for %s %s longer, until %s.',
                $lane->number,
                mb_strtolower($closure->closure_reason->label()),
                $this->spell($minutes),
                $this->log->time($closure->ends_at),
            ), $lane);

            return $closure;
        });
    }

    /**
     * Open a lane again: clears a repair and ends it in the log, and ends a
     * short closure that is under way or cancels one that hasn't started.
     */
    public function reopen(Lane $lane): Lane
    {
        return DB::transaction(function () use ($lane) {
            $lane = Lane::query()->lockForUpdate()->findOrFail($lane->id);
            $wasOutOfOrder = ! $lane->isOpen();

            $ended = $this->endPending($lane);

            $lane->repairs()->ongoing()->update(['ended_at' => now()->startOfSecond()]);

            $lane->update([
                'status' => LaneStatus::Open,
                'closed_reason' => null,
                'closed_until' => null,
            ]);

            $this->log->record('lane.reopened', sprintf(
                'Reopened lane %d%s.',
                $lane->number,
                match (true) {
                    $wasOutOfOrder => ' after repair',
                    $ended->isNotEmpty() => ', ending its '.mb_strtolower($ended->first()->closure_reason->label()).' early',
                    default => '',
                },
            ), $lane);

            return $lane;
        });
    }

    /**
     * A short closure was waiting for a session to finish, and that session
     * has now ended early: start the closure straight away instead, keeping
     * its length. $scheduledFor is when the session was going to end.
     */
    public function pullForward(Lane $lane, CarbonInterface $scheduledFor): void
    {
        $closure = $this->pending($lane)->where('starts_at', $scheduledFor)->first();
        $startsAt = now()->startOfSecond();

        if ($closure === null || $startsAt >= $closure->starts_at) {
            return;
        }

        $wasFrom = $closure->starts_at;

        $closure->update([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addSeconds((int) $closure->starts_at->diffInSeconds($closure->ends_at)),
        ]);

        $this->log->record('lane.closure_moved', sprintf(
            'Lane %d\'s %s starts at %s instead of %s, as the session before it ended early.',
            $lane->number,
            mb_strtolower($closure->closure_reason->label()),
            $this->log->time($startsAt),
            $this->log->time($wasFrom),
        ), $lane);
    }

    /**
     * The parties that have been called to the lane and not yet seated,
     * first come first served.
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function partiesCalledTo(Lane $lane): Collection
    {
        return WaitlistEntry::query()
            ->where('status', WaitlistStatus::Called->value)
            ->whereHas('booking.allocations', function (Builder $query) use ($lane) {
                $query->where('lane_id', $lane->id)->where('status', AllocationStatus::Held->value);
            })
            ->with('customer')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many reservations still to come have to be moved off the lane
     * because it is out of order, including groups checked in early. With an
     * estimate of when the lane will be back, only those before it count.
     */
    public function reservationsToMove(Lane $lane): int
    {
        return $lane->allocations()
            ->occupying()
            ->where('ends_at', '>', now())
            ->whereHas('booking', function (Builder $query) {
                $query->movable()->where('source', '!=', BookingSource::WalkIn->value);
            })
            ->get()
            ->filter(fn (LaneAllocation $allocation) => $lane->needsMovingAt($allocation->starts_at))
            ->unique('booking_id')
            ->count();
    }

    /**
     * The lane's short closure that is under way or still to start, if any.
     *
     * @return HasMany<LaneAllocation, Lane>
     */
    private function pending(Lane $lane): HasMany
    {
        return $lane->allocations()
            ->occupying()
            ->whereNotNull('closure_reason')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at');
    }

    /**
     * Undo a party's call: free every lane held for it and make it a
     * waiting party again. It keeps the time it joined, so it is still
     * ahead of everyone who joined after it, and a deposit it paid stays
     * held as if it had never been called. Returns false when it was no
     * longer called, so nothing changed.
     */
    private function returnToLine(WaitlistEntry $entry): bool
    {
        $entry = WaitlistEntry::query()->lockForUpdate()->find($entry->id);

        if ($entry?->status !== WaitlistStatus::Called || $entry->booking?->status !== BookingStatus::Pending) {
            return false;
        }

        $entry->booking->allocations()
            ->where('status', AllocationStatus::Held->value)
            ->update(['status' => AllocationStatus::Released->value]);

        $entry->booking->update(['status' => BookingStatus::Cancelled]);

        $entry->update(['status' => WaitlistStatus::Waiting, 'called_at' => null, 'booking_id' => null]);

        return true;
    }

    /**
     * End the lane's short closure now if it has started, or drop it if not.
     * Times are kept to the second, and a closure ended in the second it
     * began would have no length left, so that one is dropped too. Returns
     * the closures ended or dropped.
     *
     * @return Collection<int, LaneAllocation>
     */
    private function endPending(Lane $lane): Collection
    {
        $endedAt = now()->startOfSecond();
        $closures = $this->pending($lane)->get();

        foreach ($closures as $closure) {
            $closure->update($endedAt > $closure->starts_at
                ? ['ends_at' => $endedAt]
                : ['status' => AllocationStatus::Released]);
        }

        return $closures;
    }

    // A length of time in words, e.g. "30 minutes" or "1 hour".
    private function spell(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->cascade()->forHumans();
    }
}
