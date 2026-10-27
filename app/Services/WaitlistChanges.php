<?php

namespace App\Services;

use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

/**
 * A group in the line changing itself from its own page: its name, how many
 * people, and how long it plays. It keeps its place in line. A group can do
 * this once, and only while it is waiting; after that it is locked, and it
 * can't be changed again, by the group or by staff.
 */
class WaitlistChanges
{
    public function __construct(
        private WaitlistEstimate $estimate,
        private OpeningHours $hours,
        private WaitlistSettings $settings,
        private ActivityLog $log,
    ) {}

    /**
     * Whether the group can still change itself.
     */
    public function canChange(WaitlistEntry $entry): bool
    {
        return $entry->status === WaitlistStatus::Waiting && $entry->changed_at === null;
    }

    /**
     * Change the group, and lock it against further changes. $minutes is how
     * long its lane is to be kept; $games is set when it booked games. A
     * form sent with nothing changed does nothing, and doesn't lock it.
     *
     * A lane must still be found for the group after the change. A longer
     * session must also still end by closing time, starting after the
     * group's wait, while the last call before closing is on. A shorter one
     * is always allowed.
     *
     * @throws InvalidStateException with what to tell the group.
     */
    public function change(WaitlistEntry $entry, string $name, int $partySize, int $minutes, ?int $games): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $name, $partySize, $minutes, $games) {
            $entry = WaitlistEntry::query()->with('customer')->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== WaitlistStatus::Waiting) {
                throw new InvalidStateException(__('Your group can only be changed while it is waiting in line.'));
            }

            if ($entry->changed_at !== null) {
                throw new InvalidStateException(__('Your group has already been changed once, so it can\'t be changed again.'));
            }

            $before = $this->details($entry->customer->name, $entry->party_size, $entry->minutes, $entry->games);
            $after = $this->details($name, $partySize, $minutes, $games);

            // Sending the form unchanged doesn't use up the group's change.
            if ($before === $after) {
                return $entry;
            }

            $longer = $minutes > $entry->minutes;
            $waitBefore = $this->estimate->forEntry($entry);

            // Worked out for the group as it would be, in its own place.
            $entry->fill(['party_size' => $partySize, 'minutes' => $minutes, 'games' => $games]);
            $wait = $this->estimate->forEntry($entry);

            // Refused only when it is the change that leaves no lane for the
            // group; while no lane can be found either way, it changes nothing.
            if ($wait === null && $waitBefore !== null) {
                throw new InvalidStateException(__('We can\'t find a lane for that. Try a shorter session.'));
            }

            $closesAt = $this->hours->closesNext(now());

            if ($longer && $wait !== null && $this->settings->finishByClosing() && $closesAt !== null && now()->addMinutes($wait + $minutes)->greaterThan($closesAt)) {
                throw new InvalidStateException(__('We close at :time, and that session would end after it. Pick a shorter one.', [
                    'time' => $this->log->time($closesAt),
                ]));
            }

            $entry->changed_at = now();
            $entry->save();
            $entry->customer->update(['name' => $name]);

            $this->log->record('waitlist.changed', sprintf(
                '%s changed its group in the line.',
                $this->log->party($name, $partySize),
            ), $entry, collect($before)
                ->map(fn ($value, string $field) => [$value, $after[$field]])
                ->reject(fn (array $pair) => $pair[0] === $pair[1])
                ->all(), customerName: $name);

            return $entry;
        });
    }

    /**
     * What the log shows of a group, to compare before and after.
     *
     * @return array{Name: string, People: int, Session: string}
     */
    private function details(string $name, int $partySize, int $minutes, ?int $games): array
    {
        return ['Name' => $name, 'People' => $partySize, 'Session' => $this->log->session($minutes, $games)];
    }
}
