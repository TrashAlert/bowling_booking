<?php

use App\Enums\AllocationStatus;
use App\Models\Lane;
use App\Models\LaneAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Put an allocation straight on a lane, the way a maintenance block would be.
 */
function allocate(Lane $lane, string $startsAt, string $endsAt, AllocationStatus $status = AllocationStatus::Active): LaneAllocation
{
    // The savepoint keeps the test's own transaction usable after a rejected insert.
    return DB::transaction(fn () => LaneAllocation::create([
        'lane_id' => $lane->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => $status,
    ]));
}

test('overlapping allocations on the same lane are rejected', function () {
    $lane = Lane::factory()->create();
    allocate($lane, '2026-10-01 18:00:00', '2026-10-01 19:00:00');

    expect(fn () => allocate($lane, '2026-10-01 18:30:00', '2026-10-01 19:30:00'))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23P01'));

    $this->assertDatabaseCount('lane_allocations', 1);
});

test('back-to-back allocations on the same lane are allowed', function () {
    $lane = Lane::factory()->create();
    allocate($lane, '2026-10-01 18:00:00', '2026-10-01 19:00:00');

    allocate($lane, '2026-10-01 19:00:00', '2026-10-01 20:00:00');

    $this->assertDatabaseCount('lane_allocations', 2);
});

test('the same period can be allocated on different lanes', function () {
    [$first, $second] = Lane::factory()->count(2)->create();
    allocate($first, '2026-10-01 18:00:00', '2026-10-01 19:00:00');

    allocate($second, '2026-10-01 18:00:00', '2026-10-01 19:00:00');

    $this->assertDatabaseCount('lane_allocations', 2);
});

test('a released allocation no longer blocks its lane', function () {
    $lane = Lane::factory()->create();
    allocate($lane, '2026-10-01 18:00:00', '2026-10-01 19:00:00', AllocationStatus::Released);

    allocate($lane, '2026-10-01 18:30:00', '2026-10-01 19:30:00');

    $this->assertDatabaseCount('lane_allocations', 2);
});

test('an allocation that ends before it starts is rejected', function () {
    $lane = Lane::factory()->create();

    expect(fn () => allocate($lane, '2026-10-01 19:00:00', '2026-10-01 18:00:00'))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));

    $this->assertDatabaseCount('lane_allocations', 0);
});
