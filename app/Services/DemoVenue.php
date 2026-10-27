<?php

namespace App\Services;

use App\Actions\CreateStaffAccount;
use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\LaneClosureReason;
use App\Enums\UserRole;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Customer;
use App\Models\Lane;
use App\Models\User;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The public demo venue, "Strike Zone Bowling": a made-up bowling alley with
 * a believable evening going on and a month of history behind it, so the
 * lane board, the waitlist, reservations and the dashboard all have
 * something to show. It is only ever put into an empty database, by
 * "php artisan demo:reset".
 *
 * What is happening now is made through the app's own services, by the same
 * rules as real use. The month of history is written straight to the tables,
 * as it would be far too slow to replay, and so has no activity log lines.
 */
class DemoVenue
{
    public const NAME = 'Strike Zone Bowling';

    // The two logins the demo offers on its login page.
    public const ADMIN_EMAIL = 'owner@demo.test';

    public const ADMIN_PASSWORD = 'demo-owner';

    public const COUNTER_EMAIL = 'counter@demo.test';

    public const COUNTER_PASSWORD = 'demo-counter';

    // When the demo starts afresh each day, on the venue's clock: when it
    // opens.
    public const RESET_AT = '09:00';

    private const LANES = 12;

    // The lane that is out of order now, and the one repaired a while ago.
    private const BROKEN_LANE = 12;

    private const REPAIRED_LANE = 5;

    private const HISTORY_DAYS = 28;

    // The made-up customers' first names, a mix as at a Malaysian venue.
    private const NAMES = [
        'Aiman', 'Mei Ling', 'Daniel', 'Farah', 'Ravi', 'Siti', 'Jason', 'Priya',
        'Hafiz', 'Nurul', 'Wei Jie', 'Kavitha', 'Amir', 'Chloe', 'Arjun', 'Aisyah',
        'Haziq', 'Shu Fen', 'Marcus', 'Dinesh', 'Liyana', 'Kelvin', 'Sarah', 'Irfan',
    ];

    private User $owner;

    public function __construct(
        private CreateStaffAccount $staff,
        private Branding $branding,
        private FrontPage $frontPage,
        private OpeningHours $hours,
        private LaneInventory $lanes,
        private VenueRules $rules,
        private BookingService $bookings,
        private WaitlistService $waitlist,
        private WaitlistDeposits $deposits,
        private LaneClosures $closures,
        private LaneBlocks $blocks,
        private BookingRequests $requests,
        private GameSessions $games,
        private ActivityLog $log,
    ) {}

    /**
     * The demo's logins, for its login page.
     *
     * @return list<array{role: string, email: string, password: string}>
     */
    public static function logins(): array
    {
        return [
            ['role' => 'Owner (admin)', 'email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
            ['role' => 'Counter staff', 'email' => self::COUNTER_EMAIL, 'password' => self::COUNTER_PASSWORD],
        ];
    }

    /**
     * What every page of the demo needs to know: the logins to try, and when
     * it starts afresh. Null when this copy of the app is not the demo.
     *
     * @return array{logins: list<array{role: string, email: string, password: string}>, resetsAt: string}|null
     */
    public static function forPages(): ?array
    {
        return config('bowling.demo') ? ['logins' => self::logins(), 'resetsAt' => self::RESET_AT] : null;
    }

    /**
     * Fill an empty database with the demo venue, as it is at this moment.
     * The same $seed gives the same history, so tests can rely on it.
     */
    public function seed(int $seed = 2026): void
    {
        mt_srand($seed);

        DB::transaction(fn () => $this->log->asSystem('Demo setup', function () {
            $this->venue();
            $this->history(CarbonImmutable::now());

            // Thousands of rows have just been written. Until PostgreSQL has
            // counted them again it can pick a very slow way to read them,
            // which made the dashboard hang, so it counts them at once.
            DB::statement('analyze customers, bookings, lane_allocations, waitlist_entries, waitlist_deposits');

            $this->now(CarbonImmutable::now());
        }));
    }

    /**
     * Keep the demo busy between resets, as the customers of a real venue
     * would. Run every couple of minutes by "php artisan demo:liven", and
     * does nothing while the venue is closed. Returns what it did.
     *
     * - A group called two minutes ago or more arrives and is seated, which
     *   leaves a visitor time to seat it first. About one in eight never
     *   turns up, and misses its call.
     * - A group due for its reservation three minutes ago or more checks
     *   in. About one in seven never comes, and becomes a no-show.
     * - While fewer than three groups are waiting, another one joins, at the
     *   counter or online.
     * - Now and then a reservation request comes in, while fewer than two
     *   are waiting for a call.
     *
     * Who turns up is decided by the group's number, not by chance on each
     * run, so a group left to miss its call stays missed.
     *
     * @return list<string>
     */
    public function liven(): array
    {
        if (! $this->hours->isOpenAt(now())) {
            return [];
        }

        return $this->log->asSystem('Demo customers', function () {
            $done = [];

            $arrived = WaitlistEntry::query()
                ->where('status', WaitlistStatus::Called->value)
                ->where('called_at', '<=', now()->subMinutes(2))
                ->with('customer')
                ->get()
                ->filter(fn (WaitlistEntry $entry) => $entry->id % 8 !== 0);

            foreach ($arrived as $entry) {
                try {
                    $this->waitlist->seat($entry);
                    $done[] = "Seated {$entry->customer->name}.";
                } catch (InvalidStateException) {
                    // Its lane closed, or a visitor got there first.
                }
            }

            $due = Booking::query()
                ->where('status', BookingStatus::Confirmed->value)
                ->whereIn('source', [BookingSource::Phone->value, BookingSource::Online->value])
                ->whereHas('allocations', fn ($query) => $query
                    ->where('status', AllocationStatus::Active->value)
                    ->where('starts_at', '<=', now()->subMinutes(3)))
                ->with('customer')
                ->get()
                ->filter(fn (Booking $booking) => $booking->id % 7 !== 0);

            foreach ($due as $booking) {
                try {
                    $this->bookings->checkIn($booking);
                    $done[] = "Checked in {$booking->customer->name}.";
                } catch (InvalidStateException) {
                    // One of its lanes is closed; staff have to move it.
                }
            }

            if (WaitlistEntry::query()->where('status', WaitlistStatus::Waiting->value)->count() < 3) {
                $done[] = $this->newGroup();
            }

            if (BookingRequest::query()->waiting()->count() < 2 && $this->chance(0.25)) {
                $name = self::NAMES[mt_rand(0, count(self::NAMES) - 1)];
                $day = now(config('bowling.timezone'))->startOfDay()->addDays(mt_rand(1, 4));

                $this->requests->submit($name, $this->phone(), mt_rand(4, 12), [90, 120][mt_rand(0, 1)], $day->setTime([18, 19, 20][mt_rand(0, 2)], 0), null, null, null);
                $done[] = "{$name} asked for a reservation.";
            }

            return $done;
        });
    }

    /**
     * A new group joining the line: at the counter, or online with a
     * deposit, for a length of time or, where offered, for games.
     */
    private function newGroup(): string
    {
        $name = self::NAMES[mt_rand(0, count(self::NAMES) - 1)];
        $people = mt_rand(2, 6);
        $games = $this->games->offersGames() && $this->chance(0.3) ? mt_rand(1, 3) : null;
        $minutes = $games === null ? [60, 60, 90, 120][mt_rand(0, 3)] : $this->games->minutesFor($games, $people);

        if (! $this->chance(0.4)) {
            $this->waitlist->joinAsNewCustomer($name, null, $minutes, $people, $games);

            return "{$name} joined the line at the counter.";
        }

        $this->deposits->confirmPayment(WaitlistDeposit::create([
            'name' => $name,
            'phone' => $this->phone(),
            'minutes' => $minutes,
            'games' => $games,
            'party_size' => $people,
            'amount_cents' => $this->deposits->depositCents(),
            'payment_provider' => 'stand_in',
        ]));

        return "{$name} joined the line online.";
    }

    /**
     * Who runs the venue, what it looks like, and how it is set up.
     */
    private function venue(): void
    {
        $this->owner = $this->staff->handle('Aisyah Rahman', self::ADMIN_EMAIL, self::ADMIN_PASSWORD, UserRole::Admin);
        $this->staff->handle('Counter', self::COUNTER_EMAIL, self::COUNTER_PASSWORD, UserRole::Staff);

        $this->branding->rename(self::NAME);
        $this->branding->setBrandColor('#7c3aed');
        $this->branding->setContact('phone', '0312345678');
        $this->branding->setContact('address', 'Level 3, Demo Mall, Kuala Lumpur');

        $this->frontPage->announce('Ladies\' night every Wednesday from 8 PM.', null);

        // Open from 9 AM to 3 AM every day.
        $this->hours->save(array_fill(0, 7, ['opens' => '09:00', 'closes' => '03:00']));

        $this->lanes->resize(self::LANES);
        Lane::query()->whereIn('number', [1, 2])->update(['has_bumpers' => true]);

        // Groups choose time or games, to show both.
        $this->rules->save('session_mode', GameSessions::BOTH);
    }

    /**
     * Four weeks of sessions, no-shows, cancellations, waitlist groups,
     * re-oiling and one repair, ending three hours before $now so they never
     * meet what is happening now.
     */
    private function history(CarbonImmutable $now): void
    {
        $timezone = config('bowling.timezone');
        $today = $now->setTimezone($timezone)->startOfDay();
        $cutoff = $now->subHours(3);
        $lanes = Lane::query()->orderBy('number')->get();
        $customers = $this->customers(200);

        // Lane 5 was out of order for two days, twelve days ago.
        $repairFrom = $today->subDays(12)->setTime(14, 0);
        $repairUntil = $today->subDays(10)->setTime(11, 0);

        DB::table('lane_repairs')->insert([
            'lane_id' => $lanes->firstWhere('number', self::REPAIRED_LANE)->id,
            'started_at' => $repairFrom->utc(),
            'ended_at' => $repairUntil->utc(),
            'created_at' => $repairFrom->utc(),
            'updated_at' => $repairUntil->utc(),
        ]);

        foreach (range(self::HISTORY_DAYS, 1) as $daysAgo) {
            $day = $today->subDays($daysAgo);
            $opens = $day->setTime(9, 0);
            $closes = $day->addDay()->setTime(3, 0);
            $weekend = in_array($day->dayOfWeek, [5, 6, 0], true);

            foreach ($lanes as $lane) {
                $underRepair = fn (CarbonImmutable $from, CarbonImmutable $until) => $lane->number === self::REPAIRED_LANE
                    && $from < $repairUntil && $until > $repairFrom;

                // Each lane is re-oiled when the venue opens.
                $start = $opens;

                if (! $underRepair($start, $start->addMinutes(15)) && $start->addMinutes(15) <= $cutoff) {
                    $this->pastAllocation($lane, null, $start, $start->addMinutes(15), AllocationStatus::Active, LaneClosureReason::ReOil);
                }

                $start = $start->addMinutes(15);

                while ($start < $closes) {
                    $busy = $this->busyness($start->hour, $weekend);

                    if ($this->chance(1 - $busy)) {
                        $start = $start->addMinutes(30);

                        continue;
                    }

                    $minutes = [30, 60, 60, 60, 90, 90, 120][mt_rand(0, 6)];
                    $end = $start->addMinutes($minutes);

                    if ($end > $closes || $end > $cutoff) {
                        break;
                    }

                    if ($underRepair($start, $end)) {
                        $start = $repairUntil;

                        continue;
                    }

                    $this->pastSession($lane, $start, $end, $minutes, $busy, $customers);
                    $start = $end->addMinutes(mt_rand(0, 3) * 5);
                }
            }

            $this->pastMissedGroups($day, $customers, $cutoff);
        }
    }

    /**
     * One session that has been played, with its booking and lane time, and
     * the waitlist group it came from for most walk-ins.
     *
     * @param  Collection<int, int>  $customers
     */
    private function pastSession(Lane $lane, CarbonImmutable $start, CarbonImmutable $end, int $minutes, float $busy, Collection $customers): void
    {
        $walkIn = $this->chance(0.75);
        $status = match (true) {
            $walkIn => BookingStatus::Completed,
            $this->chance(0.05) => BookingStatus::NoShow,
            $this->chance(0.03) => BookingStatus::Cancelled,
            default => BookingStatus::Completed,
        };
        $partySize = mt_rand(2, 6);
        $customerId = $customers->random();

        $bookingId = DB::table('bookings')->insertGetId([
            'customer_id' => $customerId,
            'minutes' => $minutes,
            'party_size' => $partySize,
            'source' => $walkIn ? BookingSource::WalkIn->value : BookingSource::Phone->value,
            'status' => $status->value,
            'total_cents' => 0,
            'created_at' => ($walkIn ? $start : $start->subDays(mt_rand(1, 10)))->utc(),
            'updated_at' => $end->utc(),
        ]);

        $this->pastAllocation(
            $lane,
            $bookingId,
            $start,
            $end,
            $status === BookingStatus::Completed ? AllocationStatus::Active : AllocationStatus::Released,
        );

        if (! $walkIn || ! $this->chance(0.75)) {
            return;
        }

        $joinedAt = $start->subMinutes((int) round(mt_rand(0, 45) * $busy));

        $entryId = DB::table('waitlist_entries')->insertGetId([
            'customer_id' => $customerId,
            'minutes' => $minutes,
            'party_size' => $partySize,
            'booking_id' => $bookingId,
            'status' => WaitlistStatus::Seated->value,
            'token' => Str::random(40),
            'called_at' => $start->subMinutes(2)->utc(),
            'seated_at' => $start->utc(),
            'created_at' => $joinedAt->utc(),
            'updated_at' => $start->utc(),
        ]);

        // Some joined online; those that had to wait paid a deposit.
        if ($this->chance(0.2)) {
            $this->pastDeposit($entryId, $customerId, $minutes, $partySize, $joinedAt, $start > $joinedAt->addMinutes(5) ? 1000 : 0);
        }
    }

    /**
     * A couple of groups a day that missed their call or left the line.
     *
     * @param  Collection<int, int>  $customers
     */
    private function pastMissedGroups(CarbonImmutable $day, Collection $customers, CarbonImmutable $cutoff): void
    {
        foreach ([WaitlistStatus::Skipped, WaitlistStatus::Left, WaitlistStatus::Skipped] as $index => $status) {
            $joinedAt = $day->setTime(19 + $index, mt_rand(0, 50));

            if ($joinedAt->addHour() > $cutoff) {
                continue;
            }

            DB::table('waitlist_entries')->insert([
                'customer_id' => $customers->random(),
                'minutes' => 60,
                'party_size' => mt_rand(2, 5),
                'status' => $status->value,
                'token' => Str::random(40),
                'called_at' => $status === WaitlistStatus::Skipped ? $joinedAt->addMinutes(25)->utc() : null,
                'created_at' => $joinedAt->utc(),
                'updated_at' => $joinedAt->addMinutes(30)->utc(),
            ]);
        }
    }

    private function pastAllocation(Lane $lane, ?int $bookingId, CarbonImmutable $start, CarbonImmutable $end, AllocationStatus $status, ?LaneClosureReason $closure = null): void
    {
        DB::table('lane_allocations')->insert([
            'lane_id' => $lane->id,
            'booking_id' => $bookingId,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'status' => $status->value,
            'closure_reason' => $closure?->value,
            'note' => $closure?->label(),
            'created_at' => $start->utc(),
            'updated_at' => $end->utc(),
        ]);
    }

    private function pastDeposit(int $entryId, int $customerId, int $minutes, int $partySize, CarbonImmutable $paidAt, int $cents): void
    {
        $customer = DB::table('customers')->find($customerId);

        DB::table('waitlist_deposits')->insert([
            'token' => Str::random(40),
            'name' => $customer->name,
            // Joining online always takes a phone number.
            'phone' => $customer->phone ?? $this->phone(),
            'minutes' => $minutes,
            'party_size' => $partySize,
            'amount_cents' => $cents,
            'paid_at' => $paidAt->utc(),
            'waitlist_entry_id' => $entryId,
            'payment_provider' => $cents > 0 ? 'stand_in' : null,
            'created_at' => $paidAt->utc(),
            'updated_at' => $paidAt->utc(),
        ]);
    }

    /**
     * What is going on at this moment, made through the app's own services:
     * lanes in play, a re-oil, a lane out of order, a group late for its
     * reservation, groups called and waiting, a league night later on,
     * reservations to come and requests to call back.
     */
    private function now(CarbonImmutable $now): void
    {
        $timezone = config('bowling.timezone');
        $local = $now->setTimezone($timezone);
        $lane = fn (int $number) => Lane::query()->where('number', $number)->firstOrFail();
        $nextHour = $local->minute === 0 && $local->second === 0 ? $local : $local->startOfHour()->addHour();
        $tomorrow = $local->startOfDay()->addDay();

        // A reservation tomorrow on the lane about to break, so it is flagged
        // as needing a move once the lane is out of order.
        $this->bookings->reserveForNewCustomer('Kavitha', $this->phone(), collect([$lane(self::BROKEN_LANE)]), 90, 5, $tomorrow->setTime(20, 0));
        $this->closures->closeForRepair($lane(self::BROKEN_LANE), 2);

        // Seven lanes in play, each on its next free lane, lowest first.
        foreach ([[50, 60, null, 'Aiman'], [20, 90, null, 'Priya'], [85, 120, null, 'Jason'], [10, 60, null, 'Siti'], [35, 90, null, 'Dinesh'], [60, 120, null, 'Shu Fen'], [5, null, 3, 'Haziq']] as [$ago, $minutes, $games, $name]) {
            $this->inPlay($name, $now->subMinutes($ago), $minutes, $games);
        }

        $this->closures->closeForWork($lane(8), LaneClosureReason::ReOil, 15);

        // Due six minutes ago and not here yet; a no-show at fifteen.
        $this->bookings->reserveForNewCustomer('Jason Tan', $this->phone(), collect([$lane(9)]), 60, 4, $now->subMinutes(6)->startOfMinute());

        // Tonight's league, every week for a month, kept clear an hour before.
        $league = $nextHour->addHours(3);
        $this->blocks->block(collect([$lane(9), $lane(10), $lane(11)]), $league->utc(), 180, $league->format('l').' league', 4, $this->owner);

        // Reservations still to come.
        $this->bookings->reserveForNewCustomer('Marcus', $this->phone(), collect([$lane(1), $lane(2)]), 120, 9, $nextHour->addHours(3), 'Company team night');
        $this->bookings->reserveForNewCustomer('Aisyah', $this->phone(), collect([$lane(6)]), 90, 5, $nextHour->addHours(4), 'Birthday, bringing a cake');
        $this->bookings->reserveForNewCustomer('Nurul', $this->phone(), collect([$lane(1), $lane(2)]), 90, 6, $tomorrow->setTime(15, 0), 'Children\'s party, bumpers please');
        $this->bookings->reserveForNewCustomer('Wei Jie', $this->phone(), collect([$lane(3), $lane(4), $lane(5)]), 120, 15, $tomorrow->setTime(20, 0), 'Company event');

        // Two groups called to the free lanes, then four still waiting.
        $this->waitlist->joinAsNewCustomer('Mei Ling', $this->phone(), 60, 3);
        $this->waitlist->joinAsNewCustomer('Daniel', null, $this->games->minutesFor(2, 4), 4, 2);
        $this->waitlist->callNextParties();

        foreach ([['Ravi', 2, 90, null, false, 18], ['Farah', 5, 60, null, true, 12], ['Hafiz', 4, null, 3, true, 7], ['Chloe', 6, 120, null, false, 2]] as [$name, $people, $minutes, $games, $online, $ago]) {
            $minutes ??= $this->games->minutesFor($games, $people);

            $entry = $online
                ? $this->deposits->confirmPayment(WaitlistDeposit::create([
                    'name' => $name,
                    'phone' => $this->phone(),
                    'minutes' => $minutes,
                    'games' => $games,
                    'party_size' => $people,
                    'amount_cents' => $this->deposits->depositCents(),
                    'payment_provider' => 'stand_in',
                ]))
                : $this->waitlist->joinAsNewCustomer($name, null, $minutes, $people, $games);

            $entry->forceFill(['created_at' => $now->subMinutes($ago)])->save();
        }

        $this->requestsToCallBack($local, $tomorrow);
    }

    /**
     * A walk-in group that started playing at $start, on the lowest free lane.
     */
    private function inPlay(string $name, CarbonImmutable $start, ?int $minutes, ?int $games): void
    {
        $people = mt_rand(2, 6);
        $minutes ??= $this->games->minutesFor($games, $people);
        $customer = Customer::create(['name' => $name, 'phone' => null]);

        $booking = $this->bookings->book($customer, $minutes, $people, $start, BookingSource::WalkIn, games: $games);
        $booking->update(['status' => BookingStatus::CheckedIn]);

        WaitlistEntry::create([
            'customer_id' => $customer->id,
            'minutes' => $minutes,
            'games' => $games,
            'party_size' => $people,
            'booking_id' => $booking->id,
            'status' => WaitlistStatus::Seated,
            'called_at' => $start->subMinutes(2),
            'seated_at' => $start,
        ])->forceFill(['created_at' => $start->subMinutes(mt_rand(5, 25))])->save();
    }

    /**
     * Reservation requests: some waiting for a call, one good to call now,
     * one confirmed and one declined.
     */
    private function requestsToCallBack(CarbonImmutable $local, CarbonImmutable $tomorrow): void
    {
        $from = $local->hour === 0 ? '00:00' : $local->subHour()->format('H:00');
        $until = $local->hour >= 22 ? '23:59' : $local->addHours(2)->format('H:00');

        $this->requests->submit('Arjun', $this->phone(), 8, 120, $tomorrow->addDay()->setTime(19, 0), $from, $until, 'Office farewell');
        $this->requests->submit('Liyana', $this->phone(), 4, 60, $tomorrow->setTime(14, 0), null, null, null);
        $this->requests->submit('Kelvin Lim', $this->phone(), 12, 90, $tomorrow->addDays(2)->setTime(20, 0), '10:00', '12:00', 'Can we have lanes next to each other?');

        $declined = $this->requests->submit('Irfan', $this->phone(), 20, 120, $tomorrow->setTime(21, 0), null, null, 'Whole venue for a party');
        $this->requests->decline($declined, 'Too many lanes on a Friday night', $this->owner);

        $confirmed = $this->requests->submit('Sarah', $this->phone(), 5, 90, $tomorrow->addDay()->setTime(18, 0), null, null, null);
        $this->requests->confirm($confirmed, 'Sarah', $confirmed->phone, Lane::query()->whereIn('number', [7])->get(), 90, 5, $confirmed->starts_at, null, $this->owner);
    }

    /**
     * A pool of made-up returning customers, as their ids.
     *
     * @return Collection<int, int>
     */
    private function customers(int $count): Collection
    {
        $rows = collect(range(1, $count))->map(fn () => [
            'name' => self::NAMES[mt_rand(0, count(self::NAMES) - 1)],
            'phone' => $this->chance(0.7) ? $this->phone() : null,
            'created_at' => now()->subDays(self::HISTORY_DAYS + 1),
            'updated_at' => now()->subDays(self::HISTORY_DAYS + 1),
        ]);

        DB::table('customers')->insert($rows->all());

        return DB::table('customers')->orderByDesc('id')->limit($count)->pluck('id');
    }

    /**
     * How busy the venue is at an hour of the day, from 0 to 1: quiet in the
     * morning, busiest in the evening, busier at the weekend.
     */
    private function busyness(int $hour, bool $weekend): float
    {
        $busy = match (true) {
            $hour >= 9 && $hour < 12 => 0.25,
            $hour >= 12 && $hour < 17 => 0.45,
            $hour === 17 => 0.6,
            $hour >= 18 && $hour < 22 => 0.85,
            $hour >= 22 => 0.7,
            default => 0.45,
        };

        return min(0.95, $busy + ($weekend ? 0.1 : 0));
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    /**
     * A made-up Malaysian mobile number, e.g. "0123456789".
     */
    private function phone(): string
    {
        return '01'.mt_rand(2, 9).str_pad((string) mt_rand(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}
