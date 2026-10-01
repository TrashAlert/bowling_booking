<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LaneCountUpdateRequest;
use App\Services\LaneInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LaneController extends Controller
{
    /**
     * Show the lane settings page.
     */
    public function edit(LaneInventory $lanes): Response
    {
        return Inertia::render('settings/lanes', [
            'laneCount' => $lanes->count(),
            'maxLanes' => LaneCountUpdateRequest::MAX_LANES,
        ]);
    }

    /**
     * Change how many lanes the venue has.
     */
    public function update(LaneCountUpdateRequest $request, LaneInventory $lanes): RedirectResponse
    {
        try {
            $lanes->resize($request->integer('count'));
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            'The venue now has :count lane.|The venue now has :count lanes.',
            $lanes->count(),
        )]);

        return to_route('lanes.edit');
    }
}
