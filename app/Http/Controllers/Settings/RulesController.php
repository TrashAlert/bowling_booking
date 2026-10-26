<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RulesUpdateRequest;
use App\Services\VenueRules;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RulesController extends Controller
{
    /**
     * Show the booking rules settings page, with each rule as it is now.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/rules', [
            'rules' => [
                ...collect(RulesUpdateRequest::limits())
                    ->map(fn (array $limits, string $rule) => config("bowling.{$rule}"))
                    ->all(),
                'session_mode' => config('bowling.session_mode'),
            ],
            'limits' => RulesUpdateRequest::limits(),
        ]);
    }

    /**
     * Save the booking rules. They apply from now on; what has already been
     * booked or called keeps the times it was given.
     */
    public function update(RulesUpdateRequest $request, VenueRules $rules): RedirectResponse
    {
        foreach ($request->validated() as $rule => $value) {
            $rules->save($rule, $rule === 'session_mode' ? $value : (int) $value);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking rules saved.')]);

        return to_route('rules.edit');
    }
}
