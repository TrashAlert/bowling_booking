<?php

use App\Actions\CreateStaffAccount;
use App\Enums\LaneClosureReason;
use App\Enums\LaneStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateException;
use App\Models\ActivityLogEntry;
use App\Models\Lane;
use App\Models\User;
use App\Services\ActivityLog;
use App\Services\BookingCleanup;
use App\Services\BookingRequests;
use App\Services\BookingService;
use App\Services\LaneClosures;
use App\Services\LaneInventory;
use App\Services\VenueRules;
use App\Services\WaitlistDeposits;
use App\Services\WaitlistPush;
use App\Services\WaitlistService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// 18:00 UTC is 2:00 AM the next day at the venue, a Friday.
beforeEach(fn () => $this->travelTo('2026-10-01 18:00:00'));

/**
 * The log's lines, oldest first, as [action, summary].
 *
 * @return list<array{0: string, 1: string}>
 */
function loggedLines(): array
{
    return ActivityLogEntry::query()->orderBy('id')->get()
        ->map(fn (ActivityLogEntry $entry) => [$entry->action, $entry->summary])
        ->all();
}

test('each line is chained to the one before it, and the chain checks out', function () {
    $log = app(ActivityLog::class);

    $log->record('test.first', 'First.');
    $log->record('test.second', 'Second.');

    [$first, $second] = ActivityLogEntry::query()->orderBy('id')->get();

    expect($first->previous_hash)->toBeNull()
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($log->verify())->toBe(['checked' => 2, 'brokenAt' => null]);
});

test('a line changed directly in the database breaks the chain from there', function () {
    $log = app(ActivityLog::class);
    $log->record('test.first', 'First.');
    $log->record('test.second', 'Second.');
    $second = ActivityLogEntry::query()->orderByDesc('id')->value('id');
    DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER activity_logs_append_only');
    DB::table('activity_logs')->where('id', $second)->update(['summary' => 'Something else.']);
    DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER activity_logs_append_only');

    $result = $log->verify();

    expect($result)->toBe(['checked' => 1, 'brokenAt' => $second]);
});

test('the database refuses to change, remove or empty the log', function (Closure $change) {
    app(ActivityLog::class)->record('test.line', 'A line.');

    expect(fn () => DB::transaction($change))->toThrow(QueryException::class, 'The activity log cannot be changed');

    expect(loggedLines())->toBe([['test.line', 'A line.']]);
})->with([
    'changed' => [fn () => DB::table('activity_logs')->update(['summary' => 'Rewritten.'])],
    'removed' => [fn () => DB::table('activity_logs')->delete()],
    'emptied' => [fn () => DB::statement('TRUNCATE activity_logs')],
]);

test('lines older than 18 months are removed, the rest kept, and the removal is logged', function () {
    $log = app(ActivityLog::class);
    $this->travelTo(now()->subMonths(20));
    $log->record('test.old', 'Old.');
    $this->travelBack();
    $log->record('test.recent', 'Recent.');

    $removed = $log->prune();

    expect($removed)->toBe(1)
        ->and(loggedLines())->toBe([
            ['test.recent', 'Recent.'],
            ['log.pruned', 'Removed 1 line older than 18 months.'],
        ])
        ->and($log->verify()['brokenAt'])->toBeNull();
});

test('an action that is refused leaves no line', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    reserveLanes($lane, now()->addMinutes(90));
    $before = loggedLines();

    expect(fn () => app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 45))
        ->toThrow(InvalidStateException::class);

    expect(loggedLines())->toBe($before);
});

test('phone numbers are never written to the log', function () {
    $lane = Lane::factory()->create(['number' => 3]);
    $booking = app(BookingService::class)->reserveForNewCustomer('Farah', '0123456789', collect([$lane]), 60, 4, now()->addHour());

    app(BookingService::class)->reschedule($booking, 'Farah', '0198765432', collect([$lane]), 60, 4, now()->addHour());

    $changed = ActivityLogEntry::query()->where('action', 'reservation.changed')->sole();
    expect($changed->changes)->toBe(['Phone' => ['old number', 'new number']])
        ->and(DB::table('activity_logs')->whereLike('summary', '%0123456789%')->orWhereLike('changes', '%0123456789%')->orWhereLike('changes', '%0198765432%')->exists())->toBeFalse();
});

test('each thing that happens is logged in words', function (Closure $act, array $expected) {
    Lane::factory()->create(['number' => 3]);

    $act(Lane::query()->where('number', 3)->sole(), $this);

    expect(array_slice(loggedLines(), -count($expected)))->toBe($expected);
})->with([
    'a group joins the line' => [
        fn () => joinWaitlist(minutes: 60, partySize: 4, name: 'Farah'),
        [['waitlist.joined', "Farah's group (4 people) joined the line, for 1 hour."]],
    ],
    'a group is called' => [
        function () {
            joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
        },
        [['waitlist.called', "Called Farah's group (4 people) to lane 3."]],
    ],
    'a group is seated' => [
        function () {
            $entry = joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
            app(WaitlistService::class)->seat($entry);
        },
        [['waitlist.seated', "Seated Farah's group (4 people) on lane 3."]],
    ],
    'a group leaves the line' => [
        fn () => app(WaitlistService::class)->leave(joinWaitlist(name: 'Farah')),
        [['waitlist.left', "Farah's group (4 people) left the line."]],
    ],
    'staff skip a called group' => [
        function () {
            $entry = joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
            app(WaitlistService::class)->skip($entry);
        },
        [['waitlist.skipped', "Skipped Farah's group (4 people)."]],
    ],
    'a called group misses its turn' => [
        function (Lane $lane, $test) {
            joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
            $test->travel(6)->minutes();
            app(WaitlistService::class)->skipExpiredCalls();
        },
        [['waitlist.missed_call', "Farah's group (4 people) did not check in in time and lost its turn."]],
    ],
    'a held lane is freed' => [
        function (Lane $lane, $test) {
            joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
            $test->travel(6)->minutes();
            app(BookingCleanup::class)->releaseExpiredHolds();
        },
        [['waitlist.hold_released', "Freed lane 3 held for Farah's group (4 people): the time to check in ran out."]],
    ],
    'a called group goes back in the line when its lane breaks' => [
        function (Lane $lane) {
            joinWaitlist(name: 'Farah');
            app(WaitlistService::class)->callNextParties();
            app(LaneClosures::class)->closeForRepair($lane);
        },
        [
            ['lane.closed_for_repair', 'Closed lane 3 for repair, until reopened.'],
            ['waitlist.returned', "Put Farah's group (4 people) back in the line: lane 3, which it was called to, closed for repair."],
        ],
    ],
    'a group turns on notifications' => [
        fn () => app(WaitlistPush::class)->subscribe(joinWaitlist(name: 'Farah'), 'https://fcm.googleapis.com/x', 'key', 'auth'),
        [['waitlist.notifications_on', "Farah's group (4 people) turned on notifications."]],
    ],
    'a group joining online is asked for a deposit' => [
        function (Lane $lane) {
            app(BookingService::class)->checkIn(reserveLanes($lane, now()));
            app(WaitlistDeposits::class)->start('Farah', '0123456789', 60, 4);
        },
        [['deposit.asked', "Farah's group (4 people) started joining online, for 1 hour; a RM 10.00 deposit is due."]],
    ],
    'a group pays its deposit' => [
        function (Lane $lane) {
            app(BookingService::class)->checkIn(reserveLanes($lane, now()));
            app(WaitlistDeposits::class)->confirmPayment(app(WaitlistDeposits::class)->start('Farah', '0123456789', 60, 4));
        },
        [
            ['waitlist.joined', "Farah's group (4 people) joined the line, for 1 hour."],
            ['deposit.paid', 'Farah paid the RM 10.00 deposit.'],
        ],
    ],
    'a reservation is made' => [
        fn (Lane $lane) => reserveLanes($lane, now()->addHour(), name: 'Farah'),
        [['reservation.made', "Reserved lane 3 for Farah's group (4 people) on Fri 2 Oct, 3:00 AM, for 1 hour."]],
    ],
    'a reservation is cancelled' => [
        fn (Lane $lane) => app(BookingService::class)->cancel(reserveLanes($lane, now()->addHour(), name: 'Farah')),
        [['reservation.cancelled', "Cancelled Farah's reservation for Fri 2 Oct, 3:00 AM."]],
    ],
    'a reservation is moved' => [
        function (Lane $lane) {
            $five = Lane::factory()->create(['number' => 5]);
            $booking = reserveLanes($lane, now()->addHour(), name: 'Farah');
            $lane->update(['status' => LaneStatus::OutOfOrder]);
            app(BookingService::class)->moveLanes($booking, collect([$five]));
        },
        [['reservation.moved', "Moved Farah's reservation from lane 3 to lane 5."]],
    ],
    'a reservation is checked in' => [
        fn (Lane $lane) => app(BookingService::class)->checkIn(reserveLanes($lane, now(), name: 'Farah')),
        [['reservation.checked_in', "Checked in Farah's group (4 people) for Fri 2 Oct, 2:00 AM."]],
    ],
    'a reservation becomes a no-show' => [
        function (Lane $lane, $test) {
            reserveLanes($lane, now(), name: 'Farah');
            $test->travel(16)->minutes();
            app(BookingCleanup::class)->markNoShows();
        },
        [['reservation.no_show', "Marked Farah's group (4 people) as a no-show for Fri 2 Oct, 2:00 AM and freed lane 3."]],
    ],
    'a session is extended' => [
        function (Lane $lane) {
            $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), name: 'Farah'));
            app(BookingService::class)->extend($booking, 30);
        },
        [['session.extended', "Extended Farah's session on lane 3 by 30 minutes, to 3:30 AM."]],
    ],
    'a session is ended early' => [
        function (Lane $lane, $test) {
            app(BookingService::class)->checkIn(reserveLanes($lane, now(), name: 'Farah'));
            $test->travel(10)->minutes();
            app(BookingService::class)->endSessionOnLane($lane);
        },
        [['session.ended_early', "Ended Farah's session on lane 3 early, at 2:10 AM instead of 3:00 AM."]],
    ],
    'a session finishes' => [
        function (Lane $lane, $test) {
            app(BookingService::class)->checkIn(reserveLanes($lane, now(), name: 'Farah'));
            $test->travel(61)->minutes();
            app(BookingCleanup::class)->completeFinishedSessions();
        },
        [['session.completed', "Farah's group (4 people) finished playing on lane 3."]],
    ],
    'a customer asks for a reservation' => [
        fn () => app(BookingRequests::class)->submit('Farah', '0123456789', 4, 60, venueTime('2026-10-03 20:00'), null, null, null),
        [['request.sent', "Farah's group (4 people) asked for a reservation on Sat 3 Oct, 8:00 PM, for 1 hour."]],
    ],
    'a request is declined' => [
        function () {
            $request = app(BookingRequests::class)->submit('Farah', '0123456789', 4, 60, venueTime('2026-10-03 20:00'), null, null, null);
            app(BookingRequests::class)->decline($request, 'Fully booked', User::factory()->staff()->create());
        },
        [['request.declined', "Declined Farah's reservation request: Fully booked"]],
    ],
    'a request is confirmed' => [
        function (Lane $lane) {
            $request = app(BookingRequests::class)->submit('Farah', '0123456789', 4, 60, venueTime('2026-10-03 20:00'), null, null, null);
            app(BookingRequests::class)->confirm($request, 'Farah', '0123456789', collect([$lane]), 60, 4, venueTime('2026-10-03 20:00'), null, User::factory()->staff()->create());
        },
        [
            ['reservation.made', "Reserved lane 3 for Farah's group (4 people) on Sat 3 Oct, 8:00 PM, for 1 hour."],
            ['request.confirmed', "Confirmed Farah's reservation request."],
        ],
    ],
    'a lane is closed for a short job' => [
        fn (Lane $lane) => app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30),
        [['lane.closed_for_work', 'Closed lane 3 for re-oil, 30 minutes, from 2:00 AM to 2:30 AM.']],
    ],
    'a short closure is given more time' => [
        function (Lane $lane) {
            app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
            app(LaneClosures::class)->addTime($lane, 15);
        },
        [['lane.closure_extended', 'Kept lane 3 closed for re-oil 15 minutes longer, until 2:45 AM.']],
    ],
    'a short closure starts sooner when the session before it ends early' => [
        function (Lane $lane, $test) {
            app(BookingService::class)->checkIn(reserveLanes($lane, now(), name: 'Farah'));
            app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
            $test->travel(10)->minutes();
            app(BookingService::class)->endSessionOnLane($lane);
        },
        [
            ['lane.closure_moved', "Lane 3's re-oil starts at 2:10 AM instead of 3:00 AM, as the session before it ended early."],
            ['session.ended_early', "Ended Farah's session on lane 3 early, at 2:10 AM instead of 3:00 AM."],
        ],
    ],
    'a lane is closed for repair with an estimate' => [
        fn (Lane $lane) => app(LaneClosures::class)->closeForRepair($lane, 2),
        [['lane.closed_for_repair', 'Closed lane 3 for repair, expected back in about 2 days.']],
    ],
    'a lane is reopened after repair' => [
        function (Lane $lane) {
            app(LaneClosures::class)->closeForRepair($lane);
            app(LaneClosures::class)->reopen($lane);
        },
        [['lane.reopened', 'Reopened lane 3 after repair.']],
    ],
    'the number of lanes changes' => [
        fn () => app(LaneInventory::class)->resize(4),
        [['lanes.resized', 'Changed the number of lanes from 1 to 4.']],
    ],
    'a booking rule is set, then changed' => [
        function () {
            app(VenueRules::class)->save('waitlist_checkin_minutes', 10);
            app(VenueRules::class)->save('waitlist_checkin_minutes', 10);
            app(VenueRules::class)->save('waitlist_checkin_minutes', 15);
        },
        [
            ['settings.changed', 'Set “Minutes to check in after being called”.'],
            ['settings.changed', 'Changed “Minutes to check in after being called”.'],
        ],
    ],
    'the opening hours change' => [
        fn () => openDaily(),
        [['settings.opening_hours', 'Changed the opening hours.']],
    ],
    'a staff account is added' => [
        fn () => app(CreateStaffAccount::class)->handle('Aisyah', 'Aisyah@example.com', 'password', UserRole::Staff),
        [['user.added', 'Added Aisyah (aisyah@example.com) as staff.']],
    ],
]);

test('a setting change keeps what it was and what it became', function () {
    app(VenueRules::class)->save('waitlist_checkin_minutes', 10);

    app(VenueRules::class)->save('waitlist_checkin_minutes', 15);

    expect(ActivityLogEntry::query()->orderByDesc('id')->first()->changes)
        ->toBe(['Minutes to check in after being called' => [10, 15]]);
});

test('a scheduled job is put down to the scheduler', function () {
    Lane::factory()->create(['number' => 3]);
    joinWaitlist(name: 'Farah');

    $this->artisan('waitlist:process')->assertSuccessful();

    $called = ActivityLogEntry::query()->where('action', 'waitlist.called')->sole();
    expect([$called->actor_type, $called->actor_name])->toBe(['system', 'Scheduler']);
});
