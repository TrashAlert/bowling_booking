<?php

namespace App\Http\Controllers;

use App\Services\StaffDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The most days a chosen span can cover: a year, leap day included.
     */
    public const MAX_CUSTOM_DAYS = 366;

    /**
     * Show the page staff land on after logging in: what the lanes and the
     * line are doing, what needs dealing with now, and how the venue has been
     * doing over the days asked for.
     *
     * The days are the last 1, 7 or 30, or any span from a first to a last
     * day ("from" and "to") that ends today at the latest. A span that can't
     * be used is sent back with the reason.
     *
     * A user who has not been given a role is told nothing about the venue.
     * Each part is only worked out when the page asks for it, so refreshing
     * what needs attention does not count the analytics again.
     */
    public function __invoke(Request $request, StaffDashboard $dashboard): Response
    {
        $isStaff = $request->user()->isStaff();
        $span = $this->chosenSpan($request);

        $days = in_array($request->integer('days'), StaffDashboard::PERIODS, true)
            ? $request->integer('days')
            : StaffDashboard::DEFAULT_PERIOD;

        return Inertia::render('dashboard', [
            'rightNow' => fn () => $isStaff ? $dashboard->rightNow() : null,
            'attention' => fn () => $isStaff ? $dashboard->attention() : null,
            'analytics' => fn () => match (true) {
                ! $isStaff => null,
                $span !== null => $dashboard->analyticsBetween($span['from'], $span['to']),
                default => $dashboard->analytics($days),
            },
            'periods' => StaffDashboard::PERIODS,
            // The span chosen by its first and last day; null for the last
            // so many days.
            'customRange' => $span,
            'maxCustomDays' => self::MAX_CUSTOM_DAYS,
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    /**
     * The first and last day asked for, as "2026-10-05", or null when the
     * page was asked for the last so many days instead.
     *
     * @return array{from: string, to: string}|null
     */
    private function chosenSpan(Request $request): ?array
    {
        $today = CarbonImmutable::now(config('bowling.timezone'))->toDateString();

        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', "before_or_equal:{$today}"],
        ], [
            'from.required_with' => __('Pick the first day as well.'),
            'to.required_with' => __('Pick the last day as well.'),
            'from.before_or_equal' => __('The first day must be on or before the last day.'),
            'to.before_or_equal' => __('The last day can\'t be after today.'),
        ], [
            'from' => __('first day'),
            'to' => __('last day'),
        ]);

        // How long the span is can only be checked once both days are good.
        $validator->after(function (ValidatorInstance $validator) {
            $span = $validator->getData();

            if ($validator->errors()->isNotEmpty() || blank($span['from'] ?? null)) {
                return;
            }

            $days = CarbonImmutable::parse($span['from'])->diffInDays(CarbonImmutable::parse($span['to'])) + 1;

            if ($days > self::MAX_CUSTOM_DAYS) {
                $validator->errors()->add('from', __('Pick at most :days days.', ['days' => self::MAX_CUSTOM_DAYS]));
            }
        });

        $span = $validator->validate();

        return isset($span['from'], $span['to']) ? ['from' => $span['from'], 'to' => $span['to']] : null;
    }
}
