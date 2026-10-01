<?php

namespace App\Services;

use App\Enums\LaneStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Lane;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * How many lanes the venue has. Lanes are numbered 1 to the count.
 */
class LaneInventory
{
    public function count(): int
    {
        return Lane::query()->count();
    }

    /**
     * Make the venue have lanes numbered 1 to $count.
     *
     * Lanes above $count are removed, highest numbers first in effect. They
     * are only hidden, not deleted, so their past bookings stay intact, and
     * a lane that comes back later is the same lane again.
     *
     * @throws InvalidStateException when a lane to remove is in use or has bookings coming up.
     */
    public function resize(int $count): void
    {
        DB::transaction(function () use ($count) {
            $busy = Lane::query()
                ->where('number', '>', $count)
                ->whereHas('allocations', function (Builder $query) {
                    $query->occupying()->where('ends_at', '>', now());
                })
                ->orderBy('number')
                ->pluck('number');

            if ($busy->isNotEmpty()) {
                throw new InvalidStateException(trans_choice(
                    'Lane :lanes is in use or has bookings coming up, so it can\'t be removed.|Lanes :lanes are in use or have bookings coming up, so they can\'t be removed.',
                    $busy->count(),
                    ['lanes' => Arr::join($busy->all(), ', ', ' and ')],
                ));
            }

            Lane::query()->where('number', '>', $count)->delete();

            $existing = Lane::withTrashed()
                ->whereBetween('number', [1, $count])
                ->get()
                ->keyBy('number');

            foreach (range(1, $count) as $number) {
                $lane = $existing->get($number);

                if ($lane === null) {
                    Lane::create(['number' => $number]);
                } elseif ($lane->trashed()) {
                    $lane->status = LaneStatus::Open;
                    $lane->restore();
                }
            }
        });
    }
}
