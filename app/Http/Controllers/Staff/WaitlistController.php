<?php

namespace App\Http\Controllers\Staff;

use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreWalkInRequest;
use App\Models\Package;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WaitlistController extends Controller
{
    /**
     * Add a walk-in party to the end of the line.
     */
    public function store(StoreWalkInRequest $request, WaitlistService $waitlist): RedirectResponse
    {
        $entry = $waitlist->joinAsNewCustomer(
            $request->string('name')->toString(),
            $request->input('phone'),
            Package::findOrFail($request->integer('package_id')),
            $request->integer('party_size'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is in line.', ['name' => $entry->customer->name])]);

        return to_route('staff.board');
    }

    /**
     * Call the next parties now instead of waiting for the scheduler.
     */
    public function callNext(WaitlistService $waitlist): RedirectResponse
    {
        $called = count($waitlist->callNextParties());

        Inertia::flash('toast', $called > 0
            ? ['type' => 'success', 'message' => trans_choice('Called :count party.|Called :count parties.', $called)]
            : ['type' => 'info', 'message' => __('No waiting party fits a free lane right now.')]);

        return to_route('staff.board');
    }

    /**
     * Start the session of a called party that has come to the desk.
     */
    public function seat(WaitlistEntry $entry, WaitlistService $waitlist): RedirectResponse
    {
        try {
            $waitlist->seat($entry);

            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is seated.', ['name' => $entry->customer->name])]);
        } catch (InvalidStateException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('staff.board');
    }

    /**
     * Skip a called party that didn't turn up and free their lanes.
     */
    public function skip(WaitlistEntry $entry, WaitlistService $waitlist): RedirectResponse
    {
        $waitlist->skip($entry);

        $this->flashRemoval($entry, WaitlistStatus::Skipped, ':name was skipped.');

        return to_route('staff.board');
    }

    /**
     * Take a party out of the line.
     */
    public function destroy(WaitlistEntry $entry, WaitlistService $waitlist): RedirectResponse
    {
        $waitlist->leave($entry);

        $this->flashRemoval($entry, WaitlistStatus::Left, ':name was removed from the line.');

        return to_route('staff.board');
    }

    /**
     * Report a removal honestly: the board may be a few seconds stale, so the
     * party might already have been seated or removed by someone else.
     */
    private function flashRemoval(WaitlistEntry $entry, WaitlistStatus $expected, string $message): void
    {
        $name = $entry->customer->name;

        Inertia::flash('toast', $entry->refresh()->status === $expected
            ? ['type' => 'success', 'message' => __($message, ['name' => $name])]
            : ['type' => 'info', 'message' => __(':name is no longer in line.', ['name' => $name])]);
    }
}
