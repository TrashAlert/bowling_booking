<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OpeningHoursUpdateRequest;
use App\Services\OpeningHours;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OpeningHoursController extends Controller
{
    /**
     * Show the opening hours settings page.
     */
    public function edit(OpeningHours $hours): Response
    {
        return Inertia::render('settings/opening-hours', [
            'week' => $hours->week(),
            'isSet' => $hours->isSet(),
            'timezone' => $hours->timezone(),
        ]);
    }

    /**
     * Save the opening hours for the whole week.
     */
    public function update(OpeningHoursUpdateRequest $request, OpeningHours $hours): RedirectResponse
    {
        $hours->save($request->days());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Opening hours saved.')]);

        return to_route('opening-hours.edit');
    }
}
