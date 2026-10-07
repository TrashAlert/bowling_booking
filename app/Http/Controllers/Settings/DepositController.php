<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DepositUpdateRequest;
use App\Services\WaitlistSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DepositController extends Controller
{
    /**
     * Show the waitlist deposit settings page.
     */
    public function edit(WaitlistSettings $settings): Response
    {
        return Inertia::render('settings/deposit', [
            'depositRequired' => $settings->depositRequired(),
            // What the deposit is while it is on; set in config/bowling.php.
            'depositCents' => config('bowling.waitlist_deposit_cents'),
        ]);
    }

    /**
     * Turn the deposit for joining the waitlist online on or off.
     */
    public function update(DepositUpdateRequest $request, WaitlistSettings $settings): RedirectResponse
    {
        $settings->requireDeposit($request->boolean('deposit_required'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->depositRequired()
            ? __('Customers now pay a deposit to join online when no lane is free.')
            : __('Customers can now join the waitlist online without a deposit.')]);

        return to_route('deposit.edit');
    }
}
