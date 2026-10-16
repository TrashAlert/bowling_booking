<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OnlineWaitlistUpdateRequest;
use App\Services\WaitlistSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OnlineWaitlistController extends Controller
{
    /**
     * Show whether customers can join the waitlist online.
     */
    public function edit(WaitlistSettings $settings): Response
    {
        return Inertia::render('settings/online-waitlist', [
            'onlineOpen' => $settings->onlineJoiningOpen(),
            'closedMessage' => $settings->closedMessage(),
            'maxMessageLength' => WaitlistSettings::CLOSED_MESSAGE_LENGTH,
        ]);
    }

    /**
     * Open or close joining the waitlist online. Groups already in the line
     * stay in it either way, and staff can still add walk-ins.
     */
    public function update(OnlineWaitlistUpdateRequest $request, WaitlistSettings $settings): RedirectResponse
    {
        $settings->setOnlineJoining($request->boolean('online_open'), $request->input('closed_message'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->onlineJoiningOpen()
            ? __('Customers can join the waitlist online.')
            : __('Joining the waitlist online is closed. Customers are asked to come to the counter.')]);

        return to_route('online-waitlist.edit');
    }
}
