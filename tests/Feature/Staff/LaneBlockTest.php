<?php

use App\Models\Lane;
use App\Models\LaneBlock;
use App\Models\User;
use App\Services\LaneBlocks;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00:00');
});

/**
 * A valid form to block the given lanes for three hours from noon.
 *
 * @param  array<int, Lane>  $lanes
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function blockForm(array $lanes, array $overrides = []): array
{
    return [
        'note' => 'League night',
        'starts_at' => '2026-10-01T12:00:00.000Z',
        'minutes' => 180,
        'weeks' => 1,
        'lane_ids' => array_map(fn (Lane $lane) => $lane->id, $lanes),
        ...$overrides,
    ];
}

describe('blocking lanes', function () {
    test('staff can block lanes for several weeks', function () {
        $lane = Lane::factory()->create();
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)
            ->from(route('staff.reservations.index'))
            ->post(route('staff.lane-blocks.store'), blockForm([$lane], ['weeks' => 2]));

        $response->assertRedirect(route('staff.reservations.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The lanes are blocked for League night, every week for 2 weeks.']);
        expect(LaneBlock::query()->orderBy('starts_at')->get()->map(fn (LaneBlock $block) => [$block->starts_at->toIso8601String(), $block->created_by])->all())
            ->toBe([['2026-10-01T12:00:00+00:00', $staff->id], ['2026-10-08T12:00:00+00:00', $staff->id]]);
    });

    test('a lane that is taken is reported on the lanes and nothing is blocked', function () {
        $lane = Lane::factory()->create(['number' => 2]);
        reserveLanes($lane, CarbonImmutable::parse('2026-10-01 13:00:00'));

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lane-blocks.store'), blockForm([$lane]));

        $response->assertSessionHasErrors(['lane_ids' => 'Lane 2 is closed or not free on Thu 1 Oct, 8:00 PM. Pick other lanes or another time.']);
        expect(LaneBlock::query()->count())->toBe(0);
    });

    test('the form is checked before anything is blocked', function (array $overrides, string $field, string $message) {
        $lane = Lane::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.lane-blocks.store'), blockForm([$lane], $overrides));

        $response->assertSessionHasErrors([$field => $message]);
        expect(LaneBlock::query()->count())->toBe(0);
    })->with([
        'no note' => [['note' => ''], 'note', 'Say what the lanes are blocked for, e.g. "League night".'],
        'a start in the past' => [['starts_at' => '2026-10-01T09:00:00.000Z'], 'starts_at', 'The start time can\'t be in the past.'],
        'longer than a day' => [['minutes' => 1470], 'minutes', 'A block can last a day at most.'],
        'weeks running past the booking window' => [['weeks' => 13, 'starts_at' => '2026-12-01T12:00:00.000Z'], 'weeks', 'The last week must be within 90 days from today.'],
        'no lanes' => [['lane_ids' => []], 'lane_ids', 'Pick at least one lane.'],
    ]);

    test('only staff can block lanes', function () {
        $lane = Lane::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('staff.lane-blocks.store'), blockForm([$lane]));

        $response->assertForbidden();
        expect(LaneBlock::query()->count())->toBe(0);
    });
});

describe('removing a block', function () {
    test('staff can free blocked lanes', function () {
        $lane = Lane::factory()->create();
        $block = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 180, 'League night')->sole();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->from(route('staff.reservations.index'))
            ->delete(route('staff.lane-blocks.destroy', $block));

        $response->assertRedirect(route('staff.reservations.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The lanes blocked for League night are free again.']);
        $this->assertModelMissing($block);
    });

    test('a block that is over is left alone', function () {
        $block = app(LaneBlocks::class)->block(collect([Lane::factory()->create()]), CarbonImmutable::parse('2026-10-01 12:00:00'), 60, 'League night')->sole();
        $this->travelTo('2026-10-01 14:00:00');

        $response = $this->actingAs(User::factory()->staff()->create())
            ->delete(route('staff.lane-blocks.destroy', $block));

        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This block is already over.']);
        $this->assertModelExists($block);
    });
});

test('the reservations page lists the blocks of the day', function () {
    $lane = Lane::factory()->create(['number' => 5]);
    $block = app(LaneBlocks::class)->block(collect([$lane]), CarbonImmutable::parse('2026-10-01 12:00:00'), 180, 'League night')->sole();

    $response = $this->actingAs(User::factory()->staff()->create())
        ->get(route('staff.reservations.index', ['date' => '2026-10-01', 'tz' => 'UTC']));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('maxBlockWeeks', 13)
        ->has('blocks', 1, fn (Assert $row) => $row
            ->where('id', $block->id)
            ->where('note', 'League night')
            ->where('lanes', [['id' => $lane->id, 'number' => 5]])
            ->etc()));
});
