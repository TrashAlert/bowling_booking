<?php

use App\Enums\AllocationStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\BookingCleanup;
use App\Services\BookingService;
use App\Services\LaneAvailability;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * The periods a lane is taken for, as "start - end" strings, earliest first.
 *
 * @return array<int, string>
 */
function occupiedPeriods(Lane $lane): array
{
    return LaneAllocation::query()
        ->where('lane_id', $lane->id)
        ->occupying()
        ->orderBy('starts_at')
        ->get()
        ->map(fn (LaneAllocation $allocation) => $allocation->starts_at->format('H:i').' - '.$allocation->ends_at->format('H:i'))
        ->all();
}

describe('reserving', function () {
    test('every chosen lane is closed for the hour before and then booked', function () {
        [$one, $two, $three] = collect([1, 2, 3])->map(fn (int $number) => Lane::factory()->create(['number' => $number]));

        $booking = reserveLanes([$one, $three], now()->addHours(2), minutes: 90, partySize: 14, name: 'Farah');

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->source)->toBe(BookingSource::Phone)
            ->and($booking->minutes)->toBe(90)
            ->and($booking->party_size)->toBe(14)
            ->and($booking->customer->name)->toBe('Farah')
            ->and($booking->allocations->pluck('lane.number')->all())->toBe([1, 3])
            ->and($booking->closures)->toHaveCount(2);

        expect(occupiedPeriods($one))->toBe(['19:00 - 20:00', '20:00 - 21:30'])
            ->and(occupiedPeriods($three))->toBe(['19:00 - 20:00', '20:00 - 21:30'])
            ->and(occupiedPeriods($two))->toBe([]);
    });

    test('a start time given in another time zone is saved as the same moment', function () {
        $lane = Lane::factory()->create();

        // 04:00 on 2 October in Kuala Lumpur is 20:00 UTC on 1 October.
        reserveLanes($lane, CarbonImmutable::parse('2026-10-02 04:00', 'Asia/Kuala_Lumpur'));

        expect(occupiedPeriods($lane))->toBe(['19:00 - 20:00', '20:00 - 21:00']);
    });

    test('a party of more than six can be reserved on a single lane', function () {
        $lane = Lane::factory()->create();

        $booking = reserveLanes($lane, now()->addHours(2), partySize: 20);

        expect($booking->party_size)->toBe(20)
            ->and($booking->allocations)->toHaveCount(1);
    });

    test('a reservation less than an hour away closes the lane from now', function () {
        $lane = Lane::factory()->create();

        reserveLanes($lane, now()->addMinutes(30));

        expect(occupiedPeriods($lane))->toBe(['18:00 - 18:30', '18:30 - 19:30']);
    });

    test('a reservation that starts now has no closed period before it', function () {
        $lane = Lane::factory()->create();

        $booking = reserveLanes($lane, now());

        expect($booking->closures)->toBeEmpty()
            ->and(occupiedPeriods($lane))->toBe(['18:00 - 19:00']);
    });

    test('a lane is refused when someone is on it during the hour before', function () {
        $lane = Lane::factory()->create(['number' => 4]);
        $walkIn = joinWaitlist(minutes: 60);
        app(WaitlistService::class)->callNextParties();
        app(WaitlistService::class)->seat($walkIn);

        expect(fn () => reserveLanes($lane, now()->addMinutes(90)))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(4);
            });

        expect(Booking::query()->where('source', BookingSource::Phone->value)->count())->toBe(0)
            ->and(occupiedPeriods($lane))->toBe(['18:00 - 19:00']);
    });

    test('two reservations on one lane need a full hour between them', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addHours(2));

        expect(fn () => reserveLanes($lane, now()->addMinutes(210)))
            ->toThrow(NoLaneAvailableException::class);

        reserveLanes($lane, now()->addHours(4));

        expect(occupiedPeriods($lane))->toBe(['19:00 - 20:00', '20:00 - 21:00', '21:00 - 22:00', '22:00 - 23:00']);
    });

    test('a reservation can end just as the next one\'s closed hour begins', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addHours(2));

        reserveLanes($lane, now());

        expect(occupiedPeriods($lane))->toBe(['18:00 - 19:00', '19:00 - 20:00', '20:00 - 21:00']);
    });

    test('nothing is reserved when one of several lanes is taken', function () {
        $free = Lane::factory()->create(['number' => 1]);
        $taken = Lane::factory()->create(['number' => 2]);
        reserveLanes($taken, now()->addHours(2));

        expect(fn () => reserveLanes([$free, $taken], now()->addHours(2)))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(2);
            });

        $this->assertDatabaseCount('bookings', 1);
        expect(occupiedPeriods($free))->toBe([]);
    });

    test('a lane that is out of order cannot be reserved', function () {
        $lane = Lane::factory()->outOfOrder()->create(['number' => 7]);

        expect(fn () => reserveLanes($lane, now()->addHours(2)))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(7);
            });

        $this->assertDatabaseCount('bookings', 0);
    });
});

describe('walk-ins around a reservation', function () {
    test('a walk-in is not called onto a lane that would close during its session', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addMinutes(90));
        $entry = joinWaitlist(minutes: 60);

        $called = app(WaitlistService::class)->callNextParties();

        expect($called)->toBeEmpty()
            ->and($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);
    });

    test('a walk-in is called when its session ends before the lane closes', function () {
        $lane = Lane::factory()->create();
        reserveLanes($lane, now()->addMinutes(90));
        $entry = joinWaitlist(minutes: 30);

        $called = app(WaitlistService::class)->callNextParties();

        expect($called)->toHaveCount(1)
            ->and($entry->refresh()->status)->toBe(WaitlistStatus::Called);
    });
});

describe('cancelling', function () {
    test('cancelling frees the lane and its closed hour', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(2));

        $cancelled = app(BookingService::class)->cancel($booking);

        expect($cancelled->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Released)
            ->and($booking->closures()->sole()->status)->toBe(AllocationStatus::Released)
            ->and(occupiedPeriods($lane))->toBe([]);
    });

    test('a booking that has started or is already over cannot be cancelled', function (BookingStatus $status) {
        $booking = reserveLanes(Lane::factory()->create(), now()->addHours(2));
        $booking->update(['status' => $status]);

        expect(fn () => app(BookingService::class)->cancel($booking))
            ->toThrow(InvalidStateException::class, 'This booking can no longer be cancelled.');

        expect($booking->refresh()->status)->toBe($status)
            ->and($booking->allocations()->sole()->status)->toBe(AllocationStatus::Active);
    })->with([
        'checked in' => BookingStatus::CheckedIn,
        'completed' => BookingStatus::Completed,
        'already cancelled' => BookingStatus::Cancelled,
        'no-show' => BookingStatus::NoShow,
    ]);
});

describe('rescheduling', function () {
    test('a reservation can be moved to another time, lane and party', function () {
        $old = Lane::factory()->create(['number' => 1]);
        $new = Lane::factory()->create(['number' => 2]);
        $booking = reserveLanes($old, now()->addHours(2), name: 'Farah');

        $changed = app(BookingService::class)->reschedule(
            $booking,
            'Farah Aziz',
            '0198765432',
            collect([$new]),
            120,
            9,
            now()->addHours(4),
            'Birthday',
        );

        expect($changed->minutes)->toBe(120)
            ->and($changed->party_size)->toBe(9)
            ->and($changed->notes)->toBe('Birthday')
            ->and($changed->status)->toBe(BookingStatus::Confirmed)
            ->and($changed->customer->name)->toBe('Farah Aziz')
            ->and($changed->customer->phone)->toBe('0198765432')
            ->and(occupiedPeriods($old))->toBe([])
            ->and(occupiedPeriods($new))->toBe(['21:00 - 22:00', '22:00 - 00:00']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('lane_allocations', 2);
    });

    test('a reservation can be lengthened over its own old time', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(2), minutes: 60, name: 'Farah');

        app(BookingService::class)->reschedule($booking, 'Farah', '0123456789', collect([$lane]), 120, 4, now()->addHours(2));

        expect(occupiedPeriods($lane))->toBe(['19:00 - 20:00', '20:00 - 22:00']);
    });

    test('a reservation stays as it was when its new lane is not free', function () {
        $lane = Lane::factory()->create(['number' => 1]);
        $other = Lane::factory()->create(['number' => 2]);
        $booking = reserveLanes($lane, now()->addHours(2), partySize: 4, name: 'Farah');
        reserveLanes($other, now()->addHours(4));

        expect(fn () => app(BookingService::class)->reschedule(
            $booking,
            'Someone Else',
            '0000000000',
            collect([$other]),
            120,
            9,
            now()->addHours(4),
        ))->toThrow(NoLaneAvailableException::class);

        $booking->refresh();

        expect($booking->minutes)->toBe(60)
            ->and($booking->party_size)->toBe(4)
            ->and($booking->customer->name)->toBe('Farah')
            ->and(occupiedPeriods($lane))->toBe(['19:00 - 20:00', '20:00 - 21:00']);
    });

    test('only a confirmed reservation can be changed', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(2));
        app(BookingService::class)->cancel($booking);

        expect(fn () => app(BookingService::class)->reschedule($booking, 'Farah', '0123456789', collect([$lane]), 60, 4, now()->addHours(3)))
            ->toThrow(InvalidStateException::class, 'Only a confirmed reservation can be changed.');

        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
});

describe('on the day', function () {
    test('the closed hour does not make a reservation a no-show early', function () {
        $booking = reserveLanes(Lane::factory()->create(), now()->addHour());
        $this->travel(70)->minutes();

        $marked = app(BookingCleanup::class)->markNoShows();

        expect($marked)->toBe(0)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('a reservation nobody arrives for becomes a no-show fifteen minutes after its start', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHour());
        $this->travel(76)->minutes();

        $marked = app(BookingCleanup::class)->markNoShows();

        expect($marked)->toBe(1)
            ->and($booking->refresh()->status)->toBe(BookingStatus::NoShow)
            ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addMinutes(30)))->toBe(1);
    });

    test('a checked-in session becomes completed once its time is up', function () {
        $booking = reserveLanes(Lane::factory()->create(), now(), minutes: 60);
        app(BookingService::class)->checkIn($booking);
        $this->travel(59)->minutes();
        $early = app(BookingCleanup::class)->completeFinishedSessions();
        $this->travel(1)->minutes();

        $completed = app(BookingCleanup::class)->completeFinishedSessions();

        expect($early)->toBe(0)
            ->and($completed)->toBe(1)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Completed);
    });

    test('a reservation that was never checked in is not marked completed', function () {
        $booking = reserveLanes(Lane::factory()->create(), now(), minutes: 30);
        $this->travel(31)->minutes();

        $completed = app(BookingCleanup::class)->completeFinishedSessions();

        expect($completed)->toBe(0)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
});

describe('choosing lanes', function () {
    test('a lane is offered only when it is open and empty from its closing time to the end', function () {
        $free = Lane::factory()->create(['number' => 1]);
        $reserved = Lane::factory()->create(['number' => 2]);
        $tooSoonAfter = Lane::factory()->create(['number' => 3]);
        Lane::factory()->outOfOrder()->create(['number' => 4]);
        reserveLanes($reserved, now()->addHours(3));
        reserveLanes($tooSoonAfter, now()->addMinutes(90), minutes: 60);

        $options = app(LaneAvailability::class)->lanesForReservation(now()->addHours(3), 60);

        expect($options->map(fn (array $option) => [$option['lane']->number, $option['available']])->all())->toBe([
            [1, true],
            [2, false],
            [3, false],
            [4, false],
        ])->and($free->refresh()->isOpen())->toBeTrue();
    });

    test('the lanes of the reservation being changed count as free for it', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHours(3));

        $forOthers = app(LaneAvailability::class)->lanesForReservation(now()->addHours(3), 60);
        $forItself = app(LaneAvailability::class)->lanesForReservation(now()->addHours(3), 120, $booking);

        expect($forOthers[0]['available'])->toBeFalse()
            ->and($forItself[0]['available'])->toBeTrue();
    });
});
