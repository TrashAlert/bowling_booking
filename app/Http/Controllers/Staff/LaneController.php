<?php

namespace App\Http\Controllers\Staff;

use App\Enums\LaneStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateLaneRequest;
use App\Models\Lane;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LaneController extends Controller
{
    /**
     * Open a lane or mark it out of order. Bookings already on the lane are
     * left alone; an out-of-order lane only stops new ones.
     */
    public function update(UpdateLaneRequest $request, Lane $lane): RedirectResponse
    {
        $lane->update(['status' => $request->enum('status', LaneStatus::class)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $lane->isOpen()
            ? __('Lane :number is open.', ['number' => $lane->number])
            : __('Lane :number is out of order.', ['number' => $lane->number])]);

        return to_route('staff.board');
    }
}
