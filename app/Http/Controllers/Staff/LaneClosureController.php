<?php

namespace App\Http\Controllers\Staff;

use App\Enums\LaneClosureReason;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AddClosureTimeRequest;
use App\Http\Requests\Staff\CloseLaneRequest;
use App\Models\Lane;
use App\Services\LaneClosures;
use Carbon\CarbonInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LaneClosureController extends Controller
{
    /**
     * Close a lane: for a short job that ends by itself, or for a repair
     * that lasts until staff reopen it.
     */
    public function store(CloseLaneRequest $request, Lane $lane, LaneClosures $closures): RedirectResponse
    {
        $reason = $request->enum('reason', LaneClosureReason::class);

        if ($reason->isShort()) {
            try {
                $closure = $closures->closeForWork($lane, $reason, $request->integer('minutes'));
            } catch (InvalidStateException $exception) {
                throw ValidationException::withMessages(['minutes' => $exception->getMessage()]);
            }

            $details = ['number' => $lane->number, 'reason' => mb_strtolower($reason->label())];

            Inertia::flash('toast', ['type' => 'success', 'message' => $closure->starts_at > now()
                ? __('Lane :number will close for :reason when the session on it ends.', $details)
                : __('Lane :number is closed for :reason for :time.', [...$details, 'time' => $this->spell($request->integer('minutes'))])]);

            return to_route('staff.board');
        }

        $lane = $closures->closeForRepair($lane, $request->integer('days') ?: null);
        $toMove = $closures->reservationsToMove($lane);

        $message = __('Lane :number is out of order.', ['number' => $lane->number]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $toMove === 0
            ? $message
            : $message.' '.trans_choice(':count reservation on it needs moving.|:count reservations on it need moving.', $toMove)]);

        return to_route('staff.board');
    }

    /**
     * Give a short closure more time.
     */
    public function update(AddClosureTimeRequest $request, Lane $lane, LaneClosures $closures): RedirectResponse
    {
        try {
            $closures->addTime($lane, $request->integer('minutes'));
        } catch (InvalidStateException $exception) {
            throw ValidationException::withMessages(['minutes' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lane :number stays closed for :time more.', [
            'number' => $lane->number,
            'time' => $this->spell($request->integer('minutes')),
        ])]);

        return to_route('staff.board');
    }

    /**
     * Open a lane again, whatever it was closed for.
     */
    public function destroy(Lane $lane, LaneClosures $closures): RedirectResponse
    {
        $closures->reopen($lane);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lane :number is open.', ['number' => $lane->number])]);

        return to_route('staff.board');
    }

    // A length of time in words, e.g. "30 minutes" or "1 hour".
    private function spell(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->cascade()->forHumans();
    }
}
