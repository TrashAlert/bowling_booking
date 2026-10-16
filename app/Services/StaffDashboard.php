<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\LaneCardState;
use App\Enums\LaneStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\LaneRepair;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Read-only data for the staff dashboard: what staff have to deal with now,
 * and how the venue has been doing. Every time is an ISO 8601 string in UTC;
 * the browser converts it for display. Days are the venue's own days, in its
 * time zone, and are given as calendar dates.
 *
 * @phpstan-type Analytics array{
 *     days: int,
 *     firstDay: string,
 *     lastDay: string,
 *     sessions: array{walkIns: int, reservations: int},
 *     people: int,
 *     laneMinutes: int,
 *     noShows: int,
 *     cancelled: int,
 *     waitlist: array{joined: int, joinedOnline: int, missedCalls: int, left: int, averageWaitMinutes: int|null},
 *     byDay: list<array{date: string, walkIns: int, reservations: int}>,
 *     byHour: list<array{hour: int, sessions: int}>,
 *     byWeekday: list<array{weekday: int, days: int, sessions: list<int>}>,
 *     closedTime: list<array{number: int, repairMinutes: int, workMinutes: int}>,
 *     repairsRecordedSince: string,
 * }
 */
class StaffDashboard
{
    /**
     * How many days the analytics can cover, today included. One is today
     * alone.
     *
     * @var list<int>
     */
    public const PERIODS = [1, 7, 30];

    public const DEFAULT_PERIOD = 7;

    // When the app began logging repairs, in version 1.2. Repairs from before
    // it were never recorded.
    private const REPAIRS_RECORDED_SINCE = '2026-10-15T03:17:18+00:00';

    public function __construct(
        private BookingRequests $requests,
        private LaneBoard $board,
        private WaitlistSettings $waitlistSettings,
    ) {}

    /**
     * What the lanes and the line are doing at this moment, counted the way
     * the lane board shows them, so the two always agree.
     *
     * A lane held for a party on its way is one a called walk-in is coming
     * to, or a reservation that has started but not checked in. A lane kept
     * clear is empty for a reservation about to start.
     *
     * @return array{
     *     lanes: array{total: int, free: int, inPlay: int, heldForParty: int, outOfOrder: int, maintenance: int, keptClear: int},
     *     nextSessionEndsAt: string|null,
     *     line: array{waiting: int, called: int},
     * }
     */
    public function rightNow(): array
    {
        $lanes = collect($this->board->lanes());
        $count = fn (LaneCardState ...$states) => $lanes
            ->whereIn('state', array_map(fn (LaneCardState $state) => $state->value, $states))
            ->count();

        $line = WaitlistEntry::query()
            ->whereIn('status', WaitlistStatus::inLine())
            ->pluck('status');

        return [
            'lanes' => [
                'total' => $lanes->count(),
                'free' => $count(LaneCardState::Free),
                'inPlay' => $count(LaneCardState::InPlay),
                'heldForParty' => $count(LaneCardState::Held, LaneCardState::Reserved),
                'outOfOrder' => $count(LaneCardState::OutOfOrder),
                'maintenance' => $count(LaneCardState::Maintenance),
                'keptClear' => $count(LaneCardState::ClosedForReservation, LaneCardState::Blocked),
            ],
            'nextSessionEndsAt' => $lanes
                ->where('state', LaneCardState::InPlay->value)
                ->min('current.endsAt'),
            'line' => [
                'waiting' => $line->filter(fn (WaitlistStatus $status) => $status === WaitlistStatus::Waiting)->count(),
                'called' => $line->filter(fn (WaitlistStatus $status) => $status === WaitlistStatus::Called)->count(),
            ],
        ];
    }

    /**
     * What staff have to deal with now.
     *
     * A reservation with a lane out of order has to be moved before it can
     * check in. One that has started without checking in becomes a no-show
     * once its grace runs out. A lane out of order stays closed until staff
     * reopen it, and is overdue once its estimate has passed. Joining the
     * waitlist online stays closed until an admin opens it again.
     *
     * @return array{
     *     reservationsToMove: list<array{id: int, customerName: string, startsAt: string, closedLaneNumbers: list<int>}>,
     *     lateReservations: list<array{id: int, customerName: string, startsAt: string, noShowAt: string, laneNumbers: list<int>}>,
     *     requests: array{waiting: int, callNow: int},
     *     closedLanes: list<array{number: int, reason: string|null, until: string|null, overdue: bool}>,
     *     onlineJoiningClosed: bool,
     * }
     */
    public function attention(): array
    {
        $now = now();
        $toMove = $this->reservationsToMove($now);
        $waiting = collect($this->requests->waiting());

        return [
            'reservationsToMove' => $toMove
                ->map(fn (Booking $booking) => [
                    'id' => $booking->id,
                    'customerName' => $booking->customer->name,
                    'startsAt' => $booking->allocations->min('starts_at')->toIso8601String(),
                    'closedLaneNumbers' => $this->closedLaneNumbers($booking),
                ])
                ->all(),
            'lateReservations' => $this->lateReservations($now)
                ->reject(fn (Booking $booking) => $toMove->contains('id', $booking->id))
                ->values()
                ->map(fn (Booking $booking) => [
                    'id' => $booking->id,
                    'customerName' => $booking->customer->name,
                    'startsAt' => $booking->allocations->min('starts_at')->toIso8601String(),
                    'noShowAt' => $booking->allocations->min('starts_at')
                        ->addMinutes(config('bowling.no_show_grace_minutes'))
                        ->toIso8601String(),
                    'laneNumbers' => $booking->allocations->pluck('lane.number')->sort()->values()->all(),
                ])
                ->all(),
            'requests' => [
                'waiting' => $waiting->count(),
                'callNow' => $waiting->where('callNow', true)->count(),
            ],
            'closedLanes' => Lane::query()
                ->where('status', LaneStatus::OutOfOrder->value)
                ->orderBy('number')
                ->get()
                ->map(fn (Lane $lane) => [
                    'number' => $lane->number,
                    'reason' => $lane->closed_reason?->value,
                    'until' => $lane->closed_until?->toIso8601String(),
                    'overdue' => $lane->closed_until !== null && $lane->closed_until <= $now,
                ])
                ->all(),
            'onlineJoiningClosed' => ! $this->waitlistSettings->onlineJoiningOpen(),
        ];
    }

    /**
     * How the venue has done over the last $days days, today included.
     *
     * @return Analytics
     */
    public function analytics(int $days, bool $withClosedTime = true): array
    {
        $today = CarbonImmutable::now(config('bowling.timezone'));

        return $this->analyticsBetween($today->subDays($days - 1)->toDateString(), $today->toDateString(), $withClosedTime);
    }

    /**
     * How the venue did from $firstDay to $lastDay, both included, given as
     * calendar dates ("2026-10-05") on the venue's clock.
     *
     * A session is a party that checked in, counted on the day and in the
     * hour its lane time started. No-shows and cancellations are counted on
     * the day the reservation was for. The waitlist numbers are for the
     * groups that joined the line in those days.
     *
     * "byWeekday" is the busiest times: for each day of the week, Monday
     * first, how many sessions started in each hour that "byHour" lists, and
     * how many of that weekday the period holds, to average them over.
     *
     * "closedTime" is how long each lane was out of use, up to now: under
     * repair, and closed for a short job such as re-oiling. Repairs are only
     * known from when the log of them began, "repairsRecordedSince". With
     * $withClosedTime false it is left empty and not worked out, for a
     * dashboard that does not show it.
     *
     * @return Analytics
     */
    public function analyticsBetween(string $firstDay, string $lastDay, bool $withClosedTime = true): array
    {
        $timezone = config('bowling.timezone');
        $from = CarbonImmutable::parse($firstDay, $timezone)->startOfDay();
        $days = (int) $from->diffInDays(CarbonImmutable::parse($lastDay, $timezone)->startOfDay()) + 1;
        $until = $from->addDays($days);

        $byDay = [];
        $weekdays = [];
        $weekdayOf = [];

        foreach (range(0, $days - 1) as $offset) {
            $day = $from->addDays($offset);
            $byDay[$day->toDateString()] = ['walkIns' => 0, 'reservations' => 0];
            $weekdays[$day->dayOfWeek] = ($weekdays[$day->dayOfWeek] ?? 0) + 1;
            $weekdayOf[$day->toDateString()] = $day->dayOfWeek;
        }

        $byHour = [];
        $byWeekdayHour = [];
        $people = 0;
        $laneSeconds = 0;
        $noShows = 0;
        $cancelled = 0;

        foreach ($this->bookingTotalsBetween($from, $until) as $totals) {
            $count = (int) $totals->bookings;
            $isWalkIn = $totals->source === BookingSource::WalkIn->value;

            if ($totals->status === BookingStatus::NoShow->value) {
                $noShows += $count;
            } elseif ($totals->status === BookingStatus::Cancelled->value) {
                // A walk-in's booking is cancelled when it misses its call,
                // which the waitlist numbers already count.
                $cancelled += $isWalkIn ? 0 : $count;
            } else {
                $hour = (int) $totals->hour;
                $byDay[$totals->day][$isWalkIn ? 'walkIns' : 'reservations'] += $count;
                $byHour[$hour] = ($byHour[$hour] ?? 0) + $count;
                $byWeekdayHour[$weekdayOf[$totals->day]][$hour] = ($byWeekdayHour[$weekdayOf[$totals->day]][$hour] ?? 0) + $count;
                $people += (int) $totals->people;
                $laneSeconds += (float) $totals->active_seconds;
            }
        }

        $hours = $byHour === [] ? [] : range(min(array_keys($byHour)), max(array_keys($byHour)));

        return [
            'days' => $days,
            'firstDay' => $from->toDateString(),
            'lastDay' => $from->addDays($days - 1)->toDateString(),
            'sessions' => [
                'walkIns' => array_sum(array_column($byDay, 'walkIns')),
                'reservations' => array_sum(array_column($byDay, 'reservations')),
            ],
            'people' => $people,
            'laneMinutes' => (int) round($laneSeconds / 60),
            'noShows' => $noShows,
            'cancelled' => $cancelled,
            'waitlist' => $this->waitlistBetween($from, $until),
            'byDay' => collect($byDay)
                ->map(fn (array $sessions, string $date) => ['date' => $date, ...$sessions])
                ->values()
                ->all(),
            'byHour' => array_map(fn (int $hour) => ['hour' => $hour, 'sessions' => $byHour[$hour] ?? 0], $hours),
            'byWeekday' => $hours === [] ? [] : array_map(fn (int $weekday) => [
                'weekday' => $weekday,
                'days' => $weekdays[$weekday] ?? 0,
                'sessions' => array_map(fn (int $hour) => $byWeekdayHour[$weekday][$hour] ?? 0, $hours),
            ], OpeningHours::WEEK),
            'closedTime' => $withClosedTime ? $this->closedTimeBetween($from, $until) : [],
            'repairsRecordedSince' => self::REPAIRS_RECORDED_SINCE,
        ];
    }

    /**
     * Reservations still to check in that have a lane out of order, soonest
     * first, with only the lane time that is still to come loaded.
     *
     * @return Collection<int, Booking>
     */
    private function reservationsToMove(CarbonImmutable $now): Collection
    {
        return $this->reservationsToCheckIn()
            ->whereHas('allocations', function (Builder $query) use ($now) {
                $query->occupying()
                    ->where('ends_at', '>', $now)
                    ->whereHas('lane', fn (Builder $query) => $query->where('status', LaneStatus::OutOfOrder->value));
            })
            ->with(['customer', 'allocations' => function (Relation $query) use ($now) {
                $query->getQuery()->occupying()->where('ends_at', '>', $now)->with('lane');
            }])
            ->orderBy('id')
            ->get()
            ->filter(fn (Booking $booking) => $this->closedLaneNumbers($booking) !== [])
            ->sortBy(fn (Booking $booking) => $booking->allocations->min('starts_at')->getTimestamp())
            ->values();
    }

    /**
     * Reservations whose time has started with nobody checked in, soonest
     * first. The scheduler makes each a no-show once its grace runs out.
     *
     * @return Collection<int, Booking>
     */
    private function lateReservations(CarbonImmutable $now): Collection
    {
        $underWay = fn (Builder $query) => $query
            ->where('status', AllocationStatus::Active->value)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now);

        return $this->reservationsToCheckIn()
            ->whereHas('allocations', $underWay)
            ->with(['customer', 'allocations' => function (Relation $query) use ($underWay) {
                $underWay($query->getQuery())->with('lane');
            }])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Booking $booking) => $booking->allocations->min('starts_at')->getTimestamp())
            ->values();
    }

    /**
     * Reservations made by phone or online that have not checked in yet.
     * Walk-in sessions are bookings too, but they are run from the waitlist.
     *
     * @return Builder<Booking>
     */
    private function reservationsToCheckIn(): Builder
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('source', [BookingSource::Online->value, BookingSource::Phone->value]);
    }

    /**
     * The lanes of a reservation that it has to be moved off, lowest first.
     *
     * @return list<int>
     */
    private function closedLaneNumbers(Booking $booking): array
    {
        return $booking->allocations
            ->filter(fn (LaneAllocation $allocation) => $allocation->lane->needsMovingAt($allocation->starts_at))
            ->pluck('lane.number')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The bookings whose first lane time starts in the period, whatever
     * became of them, except those still waiting to check in or be paid for.
     * They are added up in the database, not loaded one by one, so a year of
     * them stays quick: one row for each venue day and hour, how the party
     * came and what became of it, with how many bookings, people and seconds
     * of lane time in play.
     *
     * @return Collection<int, object{day: string, hour: int, source: string, status: string, bookings: int, people: int, active_seconds: float}>
     */
    private function bookingTotalsBetween(CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $timezone = config('bowling.timezone');

        // A date is written to a query as its own clock time, so the venue's
        // days have to be turned into UTC first.
        $from = $from->utc();
        $until = $until->utc();

        // Each booking with any lane time in the period: when its lane time
        // first starts, and how long its lanes were in play.
        $laneTime = LaneAllocation::query()
            ->toBase()
            ->whereIn('booking_id', LaneAllocation::query()
                ->toBase()
                ->select('booking_id')
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<', $until))
            ->groupBy('booking_id')
            ->select('booking_id')
            ->selectRaw('min(starts_at) as starts_at')
            ->selectRaw('coalesce(sum(extract(epoch from ends_at - starts_at)) filter (where status = ?), 0) as active_seconds', [AllocationStatus::Active->value]);

        return Booking::query()
            ->toBase()
            ->joinSub($laneTime, 'lane_time', 'lane_time.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.status', [
                BookingStatus::CheckedIn->value,
                BookingStatus::Completed->value,
                BookingStatus::NoShow->value,
                BookingStatus::Cancelled->value,
            ])
            // A lane the booking gained later can fall in the period when its
            // first lane time does not; such a booking belongs to another day.
            ->where('lane_time.starts_at', '>=', $from)
            ->where('lane_time.starts_at', '<', $until)
            ->selectRaw("to_char(lane_time.starts_at at time zone ?, 'YYYY-MM-DD') as day", [$timezone])
            ->selectRaw('extract(hour from lane_time.starts_at at time zone ?)::int as hour', [$timezone])
            ->addSelect('bookings.source', 'bookings.status')
            ->selectRaw('count(*) as bookings, sum(bookings.party_size) as people, sum(lane_time.active_seconds) as active_seconds')
            ->groupBy('day', 'hour', 'bookings.source', 'bookings.status')
            ->get();
    }

    /**
     * How long each lane was out of use from $from until $until or now,
     * whichever is sooner, lowest lane number first. Lanes that were never
     * closed then are left out. A lane removed since is still listed.
     *
     * @return list<array{number: int, repairMinutes: int, workMinutes: int}>
     */
    private function closedTimeBetween(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $from = $from->utc();
        $end = $until->min(now())->utc();
        $seconds = [];

        // The part of a span that falls between $from and $end, in seconds.
        $within = fn (CarbonImmutable $start, ?CarbonImmutable $finish) => max(
            0,
            ($finish ?? $end)->min($end)->getTimestamp() - $start->max($from)->getTimestamp(),
        );

        $repairs = LaneRepair::query()
            ->where('started_at', '<', $end)
            ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get();

        foreach ($repairs as $repair) {
            $seconds[$repair->lane_id]['repair'] = ($seconds[$repair->lane_id]['repair'] ?? 0) + $within($repair->started_at, $repair->ended_at);
        }

        $shortJobs = LaneAllocation::query()
            ->occupying()
            ->whereNotNull('closure_reason')
            ->overlapping($from, $end)
            ->get();

        foreach ($shortJobs as $closure) {
            $seconds[$closure->lane_id]['work'] = ($seconds[$closure->lane_id]['work'] ?? 0) + $within($closure->starts_at, $closure->ends_at);
        }

        return Lane::withTrashed()
            ->whereKey(array_keys($seconds))
            ->orderBy('number')
            ->get()
            ->map(fn (Lane $lane) => [
                'number' => $lane->number,
                'repairMinutes' => (int) round(($seconds[$lane->id]['repair'] ?? 0) / 60),
                'workMinutes' => (int) round(($seconds[$lane->id]['work'] ?? 0) / 60),
            ])
            ->reject(fn (array $row) => $row['repairMinutes'] + $row['workMinutes'] === 0)
            ->values()
            ->all();
    }

    /**
     * What became of the groups that joined the line in the period. The wait
     * is from joining to being called, averaged over those that were called.
     *
     * @return array{joined: int, joinedOnline: int, missedCalls: int, left: int, averageWaitMinutes: int|null}
     */
    private function waitlistBetween(CarbonImmutable $from, CarbonImmutable $until): array
    {
        // Added up in the database, so a year of groups stays quick.
        $totals = WaitlistEntry::query()
            ->toBase()
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $until->utc())
            ->selectRaw('count(*) as joined')
            ->selectRaw('count(*) filter (where exists (select 1 from waitlist_deposits where waitlist_deposits.waitlist_entry_id = waitlist_entries.id)) as joined_online')
            ->selectRaw('count(*) filter (where status = ?) as missed_calls', [WaitlistStatus::Skipped->value])
            ->selectRaw('count(*) filter (where status = ?) as left_line', [WaitlistStatus::Left->value])
            ->selectRaw('avg(extract(epoch from called_at - created_at)) as average_wait_seconds')
            ->first();

        return [
            'joined' => (int) $totals->joined,
            'joinedOnline' => (int) $totals->joined_online,
            'missedCalls' => (int) $totals->missed_calls,
            'left' => (int) $totals->left_line,
            'averageWaitMinutes' => $totals->average_wait_seconds === null ? null : (int) round($totals->average_wait_seconds / 60),
        ];
    }
}
