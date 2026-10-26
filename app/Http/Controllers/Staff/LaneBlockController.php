<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\BlockLanesRequest;
use App\Models\LaneBlock;
use App\Services\LaneBlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LaneBlockController extends Controller
{
    /**
     * Block lanes for a league night or an event, once or for several weeks.
     */
    public function store(BlockLanesRequest $request, LaneBlocks $blocks): RedirectResponse
    {
        try {
            $made = $blocks->block(
                $request->lanes(),
                $request->startsAt(),
                $request->integer('minutes'),
                $request->string('note')->trim()->toString(),
                $request->integer('weeks'),
                $request->user(),
            );
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['lane_ids' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            'The lanes are blocked for :note.|The lanes are blocked for :note, every week for :count weeks.',
            $made->count(),
            ['note' => $made->first()->note],
        )]);

        return back(fallback: route('staff.reservations.index'));
    }

    /**
     * Free a block's lanes: end it now if it is under way, or drop it.
     */
    public function destroy(LaneBlock $laneBlock, LaneBlocks $blocks): RedirectResponse
    {
        try {
            $blocks->remove($laneBlock);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('The lanes blocked for :note are free again.', ['note' => $laneBlock->note])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back(fallback: route('staff.reservations.index'));
    }
}
