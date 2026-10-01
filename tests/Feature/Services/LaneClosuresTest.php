<?php

use App\Enums\AllocationStatus;
use App\Enums\LaneClosureReason;
use App\Enums\LaneStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\BookingService;
use App\Services\LaneAvailability;
use App\Services\LaneBoard;
use App\Services\LaneClosures;
use App\Services\ReservationSchedule;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * The lane's short closures that still take up the lane, as "start - end".
 *
 * @return array<int, string>
 */
function closurePeriods(Lane $lane): array
{
    return LaneAllocation::query()
        ->where('lane_id', $lane->id)
        ->whereNotNull('closure_reason')
        ->occupying()
        ->orderBy('starts_at')
        ->get()
        ->map(fn (LaneAllocation $allocation) => $allocation->starts_at->format('H:i').' - '.$allocation->ends_at->format('H:i'))
        ->all();
}

describe('a short closure', function () {
    test('starts now on a free lane and the lane reopens by itself when the time is up', function () {
        $lane = Lane::factory()->create();

        $closure = app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        expect($closure->closure_reason)->toBe(LaneClosureReason::ReOil)
            ->and($closure->booking_id)->toBeNull()
            ->and($closure->note)->toBe('Re-oil')
            ->and(closurePeriods($lane))->toBe(['18:00 - 18:30'])
            ->and($lane->refresh()->isOpen())->toBeTrue()
            ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addMinutes(30)))->toBe(0);

        $this->travel(30)->minutes();

        expect(app(LaneAvailability::class)->freeLaneCount(now(), now()->addMinutes(30)))->toBe(1);
    });

    test('waits for the session on the lane to finish', function () {
        $lane = Lane::factory()->create();
        app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));

        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::Maintenance, 30);

        expect(closurePeriods($lane))->toBe(['19:00 - 19:30']);
    });

    test('keeps walk-ins off the lane until it is over', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        $entry = joinWaitlist(minutes: 30);

        $during = app(WaitlistService::class)->callNextParties();
        $this->travel(30)->minutes();
        $after = app(WaitlistService::class)->callNextParties();

        expect($during)->toBeEmpty()
            ->and($after)->toHaveCount(1)
            ->and($entry->refresh()->status)->toBe(WaitlistStatus::Called);
    });

    test('keeps reservations off the lane, including the hour they need it empty', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        expect(fn () => reserveLanes($lane, now()->addHour()))->toThrow(NoLaneAvailableException::class);

        $booking = reserveLanes($lane, now()->addMinutes(90));

        expect($booking->allocations)->toHaveCount(1);
    });

    test('fits when it ends just as the lane closes for a reservation', function () {
        $lane = Lane::factory()->create();
        // This reservation closes the lane from 18:30.
        reserveLanes($lane, now()->addMinutes(90));

        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        expect(closurePeriods($lane))->toBe(['18:00 - 18:30']);
    });

    test('is refused when it would run into the hour a lane is closed before a reservation', function () {
        $lane = Lane::factory()->create(['number' => 3]);
        reserveLanes($lane, now()->addMinutes(90));

        expect(fn () => app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 45))
            ->toThrow(InvalidStateException::class, "Lane 3 isn't free for 45 minutes then. Pick a shorter time or close it later.");

        expect(closurePeriods($lane))->toBe([]);
    });

    test('is refused on a lane that is already closed or has a closure coming up', function (Closure $arrange) {
        $lane = Lane::factory()->create(['number' => 3]);
        $arrange($lane);

        expect(fn () => app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30))
            ->toThrow(InvalidStateException::class, 'Lane 3 is already closed or has a closure coming up.');
    })->with([
        'out of order' => [fn (Lane $lane) => app(LaneClosures::class)->closeForRepair($lane)],
        'already being re-oiled' => [fn (Lane $lane) => app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 15)],
    ]);

    test('can be given more time when the job overruns', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        app(LaneClosures::class)->addTime($lane, 15);

        expect(closurePeriods($lane))->toBe(['18:00 - 18:45']);
    });

    test('cannot be given more time than the lane is free for', function () {
        $lane = Lane::factory()->create(['number' => 3]);
        reserveLanes($lane, now()->addMinutes(90));
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        expect(fn () => app(LaneClosures::class)->addTime($lane, 15))
            ->toThrow(InvalidStateException::class, 'Lane 3 is booked too soon after to add 15 minutes.');

        expect(closurePeriods($lane))->toBe(['18:00 - 18:30']);
    });

    test('a lane with no closure has no time to add to', function () {
        $lane = Lane::factory()->create(['number' => 3]);

        expect(fn () => app(LaneClosures::class)->addTime($lane, 15))
            ->toThrow(InvalidStateException::class, 'Lane 3 has no closure to add time to.');
    });

    test('ends when the lane is reopened early', function () {
        $lane = Lane::factory()->create();
        $closure = app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        $this->travel(10)->minutes();

        app(LaneClosures::class)->reopen($lane);

        expect($closure->refresh()->ends_at->format('H:i'))->toBe('18:10')
            ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1);
    });

    test('is cancelled when the lane is reopened before it starts', function () {
        $lane = Lane::factory()->create();
        app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
        $closure = app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        app(LaneClosures::class)->reopen($lane);

        expect($closure->refresh()->status)->toBe(AllocationStatus::Released)
            ->and(closurePeriods($lane))->toBe([]);
    });

    test('stops the session before it from being extended', function () {
        $lane = Lane::factory()->create(['number' => 3]);
        $booking = app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        expect(fn () => app(BookingService::class)->extend($booking, 30))
            ->toThrow(NoLaneAvailableException::class);

        expect($booking->refresh()->minutes)->toBe(60);
    });

    test('starts straight away when the session before it ends early', function () {
        $lane = Lane::factory()->create();
        app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        $this->travel(20)->minutes();

        app(BookingService::class)->endSessionOnLane($lane);

        expect(closurePeriods($lane))->toBe(['18:20 - 18:50']);
    });
});

describe('a repair', function () {
    test('closes the lane at once with no end when no estimate is given', function () {
        $lane = Lane::factory()->create();

        $closed = app(LaneClosures::class)->closeForRepair($lane);

        expect($closed->status)->toBe(LaneStatus::OutOfOrder)
            ->and($closed->closed_reason)->toBe(LaneClosureReason::Repair)
            ->and($closed->closed_until)->toBeNull()
            ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(0);
    });

    test('records an estimate of when the lane will be back, and stays closed after it', function () {
        $lane = Lane::factory()->create();

        $closed = app(LaneClosures::class)->closeForRepair($lane, 2);
        $this->travel(3)->days();

        expect($closed->closed_until->toDateTimeString())->toBe('2026-10-03 18:00:00')
            ->and($lane->refresh()->isOpen())->toBeFalse();
    });

    test('ends a short closure that was under way on the lane', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);
        $this->travel(5)->minutes();

        app(LaneClosures::class)->closeForRepair($lane);

        expect(closurePeriods($lane))->toBe(['18:00 - 18:05'])
            ->and($lane->refresh()->isOpen())->toBeFalse();
    });

    test('only flags the reservations before the estimate', function () {
        $lane = Lane::factory()->create(['number' => 3]);
        reserveLanes($lane, now()->addDay(), name: 'Tomorrow');
        reserveLanes($lane, now()->addDays(4), name: 'Next week');

        app(LaneClosures::class)->closeForRepair($lane, 2);

        $flagged = fn (int $day) => array_column(
            app(ReservationSchedule::class)->between(now()->startOfDay()->addDays($day), now()->startOfDay()->addDays($day + 1)),
            'closedLaneNumbers',
            'customerName',
        );

        expect($flagged(1))->toBe(['Tomorrow' => [3]])
            ->and($flagged(4))->toBe(['Next week' => []])
            ->and(app(LaneClosures::class)->reservationsToMove($lane->refresh()))->toBe(1);
    });

    test('flags every reservation when there is no estimate', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addDay());
        reserveLanes($lane, now()->addDays(4));

        app(LaneClosures::class)->closeForRepair($lane);

        expect(app(LaneClosures::class)->reservationsToMove($lane->refresh()))->toBe(2);
    });

    test('flags later reservations too once the estimate has passed with the lane still closed', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addDays(4));
        app(LaneClosures::class)->closeForRepair($lane, 2);

        $before = app(LaneClosures::class)->reservationsToMove($lane->refresh());
        $this->travel(3)->days();
        $after = app(LaneClosures::class)->reservationsToMove($lane->refresh());

        expect($before)->toBe(0)
            ->and($after)->toBe(1);
    });

    test('is cleared when the lane is reopened', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addDay());
        app(LaneClosures::class)->closeForRepair($lane, 2);

        $open = app(LaneClosures::class)->reopen($lane);

        expect($open->status)->toBe(LaneStatus::Open)
            ->and($open->closed_reason)->toBeNull()
            ->and($open->closed_until)->toBeNull()
            ->and(app(LaneClosures::class)->reservationsToMove($open))->toBe(0);
    });
});

describe('on the lane board', function () {
    test('a lane closed for a short job shows why and until when', function () {
        $lane = Lane::factory()->create();
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::ReOil, 30);

        $card = app(LaneBoard::class)->lanes()[0];

        expect($card['state'])->toBe('maintenance')
            ->and($card['isOpen'])->toBeTrue()
            ->and($card['closure'])->toBeNull()
            ->and($card['current'])->toMatchArray([
                'closureReason' => 're_oil',
                'endsAt' => '2026-10-01T18:30:00+00:00',
                'isRunning' => false,
            ]);
    });

    test('a lane in play shows the closure waiting for it', function () {
        $lane = Lane::factory()->create();
        app(BookingService::class)->checkIn(reserveLanes($lane, now(), minutes: 60));
        app(LaneClosures::class)->closeForWork($lane, LaneClosureReason::Maintenance, 30);

        $card = app(LaneBoard::class)->lanes()[0];

        expect($card['state'])->toBe('in_play')
            ->and($card['current']['extendableMinutes'])->toBe(0)
            ->and($card['next'])->toBe([
                'startsAt' => '2026-10-01T19:00:00+00:00',
                'customerName' => null,
                'note' => 'Maintenance',
                'isClosure' => false,
                'closureReason' => 'maintenance',
            ]);
    });

    test('a lane under repair shows the reason, the estimate and the reservations ahead', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addDay());
        app(LaneClosures::class)->closeForRepair($lane, 2);

        $card = app(LaneBoard::class)->lanes()[0];

        expect($card['state'])->toBe('out_of_order')
            ->and($card['closure'])->toBe(['reason' => 'repair', 'until' => '2026-10-03T18:00:00+00:00'])
            ->and($card['reservationsAhead'])->toBe(1);
    });
});
