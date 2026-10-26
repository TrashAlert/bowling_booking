<?php

use App\Enums\LaneStatus;
use App\Exceptions\InvalidStateException;
use App\Models\ActivityLogEntry;
use App\Models\Lane;
use App\Models\LaneAllocation;
use App\Models\LaneBlock;
use App\Services\LaneAvailability;
use App\Services\LaneBlocks;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00:00');
});

/**
 * The lane time a block keeps, as [lane number, from, until] in UTC.
 *
 * @return list<array{int, string, string}>
 */
function blockedTimes(LaneBlock $block): array
{
    return $block->allocations()->with('lane')->get()
        ->sortBy('lane.number')
        ->map(fn (LaneAllocation $allocation) => [$allocation->lane->number, $allocation->starts_at->toIso8601String(), $allocation->ends_at->toIso8601String()])
        ->values()
        ->all();
}

test('blocked lanes are kept clear from the lead time before the start until the end', function () {
    $lanes = collect([Lane::factory()->create(['number' => 1]), Lane::factory()->create(['number' => 2])]);

    $blocks = app(LaneBlocks::class)->block($lanes, CarbonImmutable::parse('2026-10-01 12:00:00'), 180, 'League night');

    expect(blockedTimes($blocks->sole()))->toBe([
        [1, '2026-10-01T11:00:00+00:00', '2026-10-01T15:00:00+00:00'],
        [2, '2026-10-01T11:00:00+00:00', '2026-10-01T15:00:00+00:00'],
    ])
        ->and(ActivityLogEntry::query()->where('action', 'lane.blocked')->sole()->summary)
        ->toBe('Blocked lanes 1 and 2 for League night, Thu 1 Oct, 8:00 PM to 11:00 PM.');
});

test('a block can be repeated on the same weekday and time for several weeks', function () {
    $lane = Lane::factory()->create();

    $blocks = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night', weeks: 3);

    expect($blocks->map(fn (LaneBlock $block) => $block->starts_at->toIso8601String())->all())->toBe([
        '2026-10-01T12:00:00+00:00',
        '2026-10-08T12:00:00+00:00',
        '2026-10-15T12:00:00+00:00',
    ]);
});

test('nothing is blocked when a lane is taken in any of the weeks', function () {
    $free = Lane::factory()->create(['number' => 1]);
    $taken = Lane::factory()->create(['number' => 2]);
    reserveLanes($taken, CarbonImmutable::parse('2026-10-08 12:30:00'));

    $block = fn () => app(LaneBlocks::class)->block(collect([$free, $taken]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night', weeks: 2);

    expect($block)->toThrow(InvalidStateException::class, 'Lane 2 is closed or not free on Thu 8 Oct, 8:00 PM.')
        ->and(LaneBlock::query()->count())->toBe(0)
        ->and(LaneAllocation::query()->whereNotNull('lane_block_id')->count())->toBe(0);
});

test('a lane that is out of order cannot be blocked', function () {
    $lane = Lane::factory()->create(['number' => 3, 'status' => LaneStatus::OutOfOrder]);

    $block = fn () => app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'Birthday party');

    expect($block)->toThrow(InvalidStateException::class, 'Lane 3 is closed or not free');
});

test('nobody in the waitlist is called to a blocked lane', function () {
    $lane = Lane::factory()->create();
    app(LaneBlocks::class)->block(collect([$lane]), now()->addMinutes(30), 120, 'School trip');
    joinWaitlist(minutes: 30);

    $called = app(WaitlistService::class)->callNextParties();

    expect($called)->toBe([]);
});

test('removing a block frees its lanes', function () {
    $lane = Lane::factory()->create(['number' => 4]);
    $block = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night')->sole();

    app(LaneBlocks::class)->remove($block);

    expect(app(LaneAvailability::class)->freeLanes(now(), now()->addHours(4))->pluck('number')->all())->toBe([4])
        ->and(LaneBlock::query()->count())->toBe(0)
        ->and(ActivityLogEntry::query()->where('action', 'lane.unblocked')->sole()->summary)
        ->toBe('Removed the League night block on lane 4, Thu 1 Oct, 8:00 PM to 9:00 PM.');
});

test('removing a block under way ends it now', function () {
    $lane = Lane::factory()->create(['number' => 4]);
    $block = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night')->sole();
    $this->travelTo('2026-10-01 12:20:00');

    app(LaneBlocks::class)->remove($block);

    expect(app(LaneAvailability::class)->freeLaneCount(now(), now()->addHour()))->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'lane.unblocked')->sole()->summary)
        ->toStartWith('Ended the League night block');
});

test('a block that is over cannot be removed', function () {
    $block = app(LaneBlocks::class)->block(collect([Lane::factory()->create()]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night')->sole();
    $this->travelTo('2026-10-01 13:00:00');

    expect(fn () => app(LaneBlocks::class)->remove($block))->toThrow(InvalidStateException::class, 'This block is already over.')
        ->and($block->allocations()->count())->toBe(1);
});

test('the blocks of a day are listed earliest first', function () {
    $lane = Lane::factory()->create(['number' => 2]);
    $late = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 14:00:00'), 60, 'Late event')->sole();
    $early = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night')->sole();
    app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-02 12:00:00'), 60, 'Next day');

    $listed = app(LaneBlocks::class)->between(CarbonImmutable::parse('2026-10-01 00:00:00'), CarbonImmutable::parse('2026-10-02 00:00:00'));

    expect($listed)->toBe([
        [
            'id' => $early->id,
            'note' => 'League night',
            'startsAt' => '2026-10-01T12:00:00+00:00',
            'endsAt' => '2026-10-01T13:00:00+00:00',
            'keptClearFrom' => '2026-10-01T11:00:00+00:00',
            'lanes' => [['id' => $lane->id, 'number' => 2]],
            'createdBy' => null,
        ],
        [
            'id' => $late->id,
            'note' => 'Late event',
            'startsAt' => '2026-10-01T14:00:00+00:00',
            'endsAt' => '2026-10-01T15:00:00+00:00',
            'keptClearFrom' => '2026-10-01T13:00:00+00:00',
            'lanes' => [['id' => $lane->id, 'number' => 2]],
            'createdBy' => null,
        ],
    ]);
});
