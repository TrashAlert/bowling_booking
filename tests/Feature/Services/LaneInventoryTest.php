<?php

use App\Enums\AllocationStatus;
use App\Enums\LaneStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Services\LaneAvailability;
use App\Services\LaneInventory;

beforeEach(function () {
    $this->travelTo('2026-10-01 18:00:00');
});

test('raising the count adds open lanes numbered after the existing ones', function () {
    Lane::factory()->create(['number' => 1]);
    Lane::factory()->create(['number' => 2]);

    app(LaneInventory::class)->resize(4);

    expect(Lane::query()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3, 4])
        ->and(app(LaneInventory::class)->count())->toBe(4)
        ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(4);
});

test('lowering the count removes the highest-numbered lanes', function () {
    foreach ([1, 2, 3] as $number) {
        Lane::factory()->create(['number' => $number]);
    }

    app(LaneInventory::class)->resize(1);

    expect(Lane::query()->pluck('number')->all())->toBe([1])
        ->and(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1);
});

test('a lane that is in use or booked cannot be removed', function (string $startsAt, string $endsAt) {
    Lane::factory()->create(['number' => 1]);
    $lane = Lane::factory()->create(['number' => 2]);
    LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => AllocationStatus::Active,
    ]);

    expect(fn () => app(LaneInventory::class)->resize(1))
        ->toThrow(InvalidStateException::class, "Lane 2 is in use or has bookings coming up, so it can't be removed.");

    $this->assertNotSoftDeleted($lane);
})->with([
    'a session under way' => ['2026-10-01 17:30:00', '2026-10-01 18:30:00'],
    'a booking later today' => ['2026-10-01 20:00:00', '2026-10-01 21:00:00'],
]);

test('every busy lane is named when several cannot be removed', function () {
    Lane::factory()->create(['number' => 1]);

    foreach ([2, 3] as $number) {
        LaneAllocation::create([
            'lane_id' => Lane::factory()->create(['number' => $number])->id,
            'starts_at' => '2026-10-01 20:00:00',
            'ends_at' => '2026-10-01 21:00:00',
            'status' => AllocationStatus::Active,
        ]);
    }

    expect(fn () => app(LaneInventory::class)->resize(1))
        ->toThrow(InvalidStateException::class, "Lanes 2 and 3 are in use or have bookings coming up, so they can't be removed.");

    expect(app(LaneInventory::class)->count())->toBe(3);
});

test('a lane with only past or released bookings is removed and keeps its history', function () {
    Lane::factory()->create(['number' => 1]);
    $lane = Lane::factory()->create(['number' => 2]);
    $past = LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => '2026-10-01 15:00:00',
        'ends_at' => '2026-10-01 16:00:00',
        'status' => AllocationStatus::Active,
    ]);
    LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => '2026-10-01 20:00:00',
        'ends_at' => '2026-10-01 21:00:00',
        'status' => AllocationStatus::Released,
    ]);

    app(LaneInventory::class)->resize(1);

    $this->assertSoftDeleted($lane);
    expect($past->refresh()->lane->number)->toBe(2);
});

test('a removed lane comes back as the same lane and open', function () {
    Lane::factory()->create(['number' => 1]);
    $lane = Lane::factory()->outOfOrder()->create(['number' => 2]);
    app(LaneInventory::class)->resize(1);

    app(LaneInventory::class)->resize(2);

    $this->assertNotSoftDeleted($lane);
    expect($lane->refresh()->status)->toBe(LaneStatus::Open)
        ->and(Lane::withTrashed()->count())->toBe(2);
});
