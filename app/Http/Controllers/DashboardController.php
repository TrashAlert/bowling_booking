<?php

namespace App\Http\Controllers;

use App\Services\StaffDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the page staff land on after logging in: what needs dealing with
     * now, and how the venue has been doing over the days asked for.
     *
     * A user who has not been given a role is told nothing about the venue.
     * Each part is only worked out when the page asks for it, so refreshing
     * what needs attention does not count the analytics again.
     */
    public function __invoke(Request $request, StaffDashboard $dashboard): Response
    {
        $isStaff = $request->user()->isStaff();

        $days = in_array($request->integer('days'), StaffDashboard::PERIODS, true)
            ? $request->integer('days')
            : StaffDashboard::DEFAULT_PERIOD;

        return Inertia::render('dashboard', [
            'attention' => fn () => $isStaff ? $dashboard->attention() : null,
            'analytics' => fn () => $isStaff ? $dashboard->analytics($days) : null,
            'periods' => StaffDashboard::PERIODS,
            'serverNow' => now()->toIso8601String(),
        ]);
    }
}
