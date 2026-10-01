<?php

namespace App\Services;

use App\Enums\LaneStatus;
use App\Models\Lane;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LaneAvailability
{
    /**
     * Open lanes with nothing booked between $start and $end, lowest number first.
     *
     * @return Collection<int, Lane>
     */
    public function freeLanes(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Lane::query()
            ->where('status', LaneStatus::Open->value)
            ->whereDoesntHave('allocations', function (Builder $query) use ($start, $end) {
                $query->occupying()->overlapping($start, $end);
            })
            ->orderBy('number')
            ->get();
    }

    public function freeLaneCount(CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->freeLanes($start, $end)->count();
    }
}
