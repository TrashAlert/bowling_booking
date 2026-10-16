<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DashboardPartsUpdateRequest;
use App\Services\DashboardSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardPartsController extends Controller
{
    /**
     * Show which parts of the staff dashboard are turned on.
     */
    public function edit(DashboardSettings $settings): Response
    {
        return Inertia::render('settings/dashboard-parts', [
            'parts' => $settings->shown(),
        ]);
    }

    /**
     * Turn parts of the dashboard on or off, for everyone.
     */
    public function update(DashboardPartsUpdateRequest $request, DashboardSettings $settings): RedirectResponse
    {
        $settings->save($request->shownParts());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dashboard settings saved.')]);

        return to_route('dashboard-parts.edit');
    }
}
