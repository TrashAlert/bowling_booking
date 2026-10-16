<?php

namespace App\Http\Controllers\Staff;

use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreWalkInRequest;
use App\Models\WaitlistEntry;
use App\Services\WaitlistEstimate;
use App\Services\WaitlistLink;
use App\Services\WaitlistPush;
use App\Services\WaitlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;

class WaitlistController extends Controller
{
    /**
     * Add a walk-in party to the end of the line.
     *
     * A party that has to wait, because no lane is free for it now, is given
     * a QR code to scan, which opens its own page about its place in the
     * line. A party a lane is free for is called within the minute, so it
     * gets none.
     */
    public function store(StoreWalkInRequest $request, WaitlistService $waitlist, WaitlistEstimate $estimate): RedirectResponse
    {
        $entry = $waitlist->joinAsNewCustomer(
            $request->string('name')->toString(),
            $request->input('phone'),
            $request->integer('minutes'),
            $request->integer('party_size'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is in line.', ['name' => $entry->customer->name])]);

        $wait = $estimate->forEntry($entry);

        if ($wait !== 0) {
            Inertia::flash('walkInTicket', [
                'id' => $entry->id,
                'name' => $entry->customer->name,
                'position' => $entry->position(),
                // Null when no lane can be found for the party at all.
                'estimatedWaitMinutes' => $wait,
            ]);
        }

        return to_route('staff.board');
    }

    /**
     * The QR code a party in line scans to open its own page, as an image.
     * Only drawn while the party is still in line.
     */
    public function qrCode(WaitlistEntry $entry, WaitlistLink $link): Response
    {
        abort_unless(in_array($entry->status->value, WaitlistStatus::inLine(), true), 404);

        return response($link->qrCodeSvg($entry), 200, [
            'Content-Type' => 'image/svg+xml',
            // It carries the party's secret link, so it is never kept.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Call the next parties now instead of waiting for the scheduler.
     */
    public function callNext(WaitlistService $waitlist, WaitlistPush $push): RedirectResponse
    {
        $parties = $waitlist->callNextParties();
        $called = count($parties);

        // Sent once the page has gone back to staff, so they aren't kept waiting.
        defer(fn () => $push->notifyCalled($parties));

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
