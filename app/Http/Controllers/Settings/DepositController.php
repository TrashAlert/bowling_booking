<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DepositUpdateRequest;
use App\Services\VenueRules;
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
            // What the deposit is while it is on.
            'depositCents' => config('bowling.waitlist_deposit_cents'),
            'limits' => [
                'minAmount' => DepositUpdateRequest::MIN_AMOUNT,
                'maxAmount' => DepositUpdateRequest::MAX_AMOUNT,
            ],
        ]);
    }

    /**
     * Turn the deposit for joining the waitlist online on or off, and set
     * how much it is. A party that has already been asked for a deposit
     * keeps the amount it was asked for.
     */
    public function update(DepositUpdateRequest $request, WaitlistSettings $settings, VenueRules $rules): RedirectResponse
    {
        $settings->requireDeposit($request->boolean('deposit_required'));
        $rules->save('waitlist_deposit_cents', $request->depositCents());

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->depositRequired()
            ? __('Customers now pay a :amount deposit to join online when no lane is free.', [
                'amount' => config('bowling.currency_symbol').' '.number_format($request->depositCents() / 100, 2),
            ])
            : __('Customers can now join the waitlist online without a deposit.')]);

        return to_route('deposit.edit');
    }
}
