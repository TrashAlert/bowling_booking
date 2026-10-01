<?php

use App\Enums\BookingStatus;
use App\Enums\LaneStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\BookingService;
use App\Services\LaneBoard;
use App\Services\ReservationSchedule;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

/**
 * The periods each lane is taken for by this booking, closed hour included,
 * as "start - end" strings keyed by lane number.
 *
 * @return array<int, array<int, string>>
 */
function lanePeriods(Booking $booking): array
{
    return LaneAllocation::query()
        ->where(fn ($query) => $query->where('booking_id', $booking->id)->orWhere('closed_for_booking_id', $booking->id))
        ->occupying()
        ->with('lane')
        ->orderBy('starts_at')
        ->get()
        ->groupBy('lane.number')
        ->map(fn ($allocations) => $allocations
            ->map(fn (LaneAllocation $allocation) => $allocation->starts_at->format('H:i').' - '.$allocation->ends_at->format('H:i'))
            ->all())
        ->sortKeys()
        ->all();
}

describe('checking in', function () {
    test('a group cannot check in while one of its lanes is closed', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $booking = reserveLanes([$three, $four], now());
        $three->update(['status' => LaneStatus::OutOfOrder]);

        expect(fn () => app(BookingService::class)->checkIn($booking))
            ->toThrow(InvalidStateException::class, 'Lane 3 is closed. Move the group to another lane before checking in.');

        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('every closed lane is named when several are closed', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $booking = reserveLanes([$three, $four], now());
        Lane::query()->update(['status' => LaneStatus::OutOfOrder->value]);

        expect(fn () => app(BookingService::class)->checkIn($booking))
            ->toThrow(InvalidStateException::class, 'Lanes 3 and 4 are closed. Move the group to other lanes before checking in.');
    });

    test('a group can check in once its lane is reopened', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now());
        $lane->update(['status' => LaneStatus::OutOfOrder]);
        $lane->update(['status' => LaneStatus::Open]);

        $checkedIn = app(BookingService::class)->checkIn($booking);

        expect($checkedIn->status)->toBe(BookingStatus::CheckedIn);
    });
});

describe('moving', function () {
    test('a closed lane is swapped for a free one and the group can then check in', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes([$three, $four], now(), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        $moved = app(BookingService::class)->moveLanes($booking, collect([$four, $five]));

        expect($moved->minutes)->toBe(60)
            ->and(lanePeriods($booking))->toBe([
                4 => ['18:00 - 19:00'],
                5 => ['18:00 - 19:00'],
            ])
            ->and(app(BookingService::class)->checkIn($booking)->status)->toBe(BookingStatus::CheckedIn);
    });

    test('a lane gained after the start time is booked from now, not from the start', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes($three, now(), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);
        $this->travel(10)->minutes();

        app(BookingService::class)->moveLanes($booking, collect([$five]));

        expect(lanePeriods($booking))->toBe([5 => ['18:10 - 19:00']]);
    });

    test('a lane gained ahead of time is closed for the hour before, like any reserved lane', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes($three, now()->addHours(3), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        app(BookingService::class)->moveLanes($booking, collect([$five]));

        expect(lanePeriods($booking))->toBe([5 => ['20:00 - 21:00', '21:00 - 22:00']]);
    });

    test('a lane cannot be gained ahead of time when it is in use during its closed hour', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes($three, now()->addHours(3), minutes: 60);
        // Lane 5 is reserved until 20:30, half an hour into the hour it would need to be closed.
        reserveLanes($five, now()->addMinutes(90), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        expect(fn () => app(BookingService::class)->moveLanes($booking, collect([$five])))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(5);
            });

        expect(lanePeriods($booking))->toBe([3 => ['20:00 - 21:00', '21:00 - 22:00']]);
    });

    test('a group with several lanes can drop the closed one and keep the rest', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $booking = reserveLanes([$three, $four], now()->addMinutes(30), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        app(BookingService::class)->moveLanes($booking, collect([$four]));

        expect(lanePeriods($booking))->toBe([4 => ['18:00 - 18:30', '18:30 - 19:30']]);
    });

    test('nothing changes when the new lane is already taken', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        $five = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes([$three, $four], now(), minutes: 60);
        reserveLanes($five, now(), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        expect(fn () => app(BookingService::class)->moveLanes($booking, collect([$four, $five])))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(5);
            });

        expect(lanePeriods($booking))->toBe([
            3 => ['18:00 - 19:00'],
            4 => ['18:00 - 19:00'],
        ]);
    });

    test('a reservation cannot be kept on a lane that is closed', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $booking = reserveLanes($three, now(), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        expect(fn () => app(BookingService::class)->moveLanes($booking, collect([$three->refresh()])))
            ->toThrow(function (NoLaneAvailableException $exception) {
                expect($exception->laneNumber)->toBe(3);
            });
    });

    test('only a confirmed reservation that is still to come can be moved', function (Closure $arrange) {
        $lane = Lane::factory()->create(['number' => 3]);
        $other = Lane::factory()->create(['number' => 5]);
        $booking = reserveLanes($lane, now(), minutes: 60);
        $arrange($booking, $this);

        expect(fn () => app(BookingService::class)->moveLanes($booking, collect([$other])))
            ->toThrow(InvalidStateException::class, 'Only a confirmed reservation that is still to come can be moved.');

        $this->assertDatabaseMissing('lane_allocations', ['lane_id' => $other->id]);
    })->with([
        'checked in' => [fn (Booking $booking) => app(BookingService::class)->checkIn($booking)],
        'cancelled' => [fn (Booking $booking) => app(BookingService::class)->cancel($booking)],
        'already over' => [fn (Booking $booking, $test) => $test->travel(61)->minutes()],
    ]);
});

describe('flagging', function () {
    test('a reservation on a closed lane is flagged on the board and the day list', function () {
        $three = Lane::factory()->create(['number' => 3]);
        $four = Lane::factory()->create(['number' => 4]);
        reserveLanes([$three, $four], now()->addHour(), minutes: 60);
        $three->update(['status' => LaneStatus::OutOfOrder]);

        $onBoard = app(LaneBoard::class)->reservations()[0];
        $onDayList = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay())[0];

        expect($onBoard['closedLaneNumbers'])->toBe([3])
            ->and($onBoard['lanes'])->toBe([
                ['id' => $three->id, 'number' => 3],
                ['id' => $four->id, 'number' => 4],
            ])
            ->and($onDayList['closedLaneNumbers'])->toBe([3]);
    });

    test('a reservation whose lanes are all open is not flagged', function () {
        reserveLanes(Lane::factory()->create(), now()->addHour());
        Lane::factory()->outOfOrder()->create();

        $onBoard = app(LaneBoard::class)->reservations()[0];

        expect($onBoard['closedLaneNumbers'])->toBe([]);
    });

    test('a cancelled reservation on a closed lane is not flagged', function () {
        $lane = Lane::factory()->create();
        $booking = reserveLanes($lane, now()->addHour());
        app(BookingService::class)->cancel($booking);
        $lane->update(['status' => LaneStatus::OutOfOrder]);

        $onDayList = app(ReservationSchedule::class)->between(now()->startOfDay(), now()->startOfDay()->addDay())[0];

        expect($onDayList['closedLaneNumbers'])->toBe([]);
    });
});
