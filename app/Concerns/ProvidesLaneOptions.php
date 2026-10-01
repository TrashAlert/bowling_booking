<?php

namespace App\Concerns;

use App\Models\Booking;
use App\Services\LaneAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\OptionalProp;

/**
 * For staff pages with a form in which lanes are ticked for a reservation.
 */
trait ProvidesLaneOptions
{
    /**
     * Every lane and whether it is free for a reservation, as a prop that is
     * only worked out when the page asks for it by name. The start time and
     * length come from the query string, with "booking" set to the
     * reservation being changed so its own lanes count as free.
     */
    protected function laneOptions(Request $request, LaneAvailability $availability): OptionalProp
    {
        return Inertia::optional(function () use ($request, $availability) {
            $query = $request->validate([
                'starts_at' => ['required', 'date'],
                'minutes' => ['required', 'integer', 'min:1'],
                'booking' => ['nullable', 'integer'],
            ]);

            return $availability
                ->lanesForReservation(
                    CarbonImmutable::parse($query['starts_at'])->utc(),
                    (int) $query['minutes'],
                    isset($query['booking']) ? Booking::query()->whereKey((int) $query['booking'])->first() : null,
                )
                ->map(fn (array $option) => [
                    'id' => $option['lane']->id,
                    'number' => $option['lane']->number,
                    'hasBumpers' => $option['lane']->has_bumpers,
                    'available' => $option['available'],
                ])
                ->values()
                ->all();
        });
    }
}
