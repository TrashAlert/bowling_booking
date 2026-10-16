<?php

namespace App\Http\Middleware;

use App\Services\BookingRequests;
use App\Services\Branding;
use App\Services\FrontPage;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // The business name ("name") and the address of its logo
            // ("logoUrl", null when it has none), as set under Settings.
            ...app(Branding::class)->forPages(),
            // The banner across the top of the customer pages, while one is
            // up; null when there is none or it has ended.
            'announcement' => fn () => app(FrontPage::class)->currentAnnouncement(),
            'version' => config('bowling.version'),
            'currencySymbol' => config('bowling.currency_symbol'),
            // How many reservation requests are waiting, for the staff sidebar.
            'requestsWaiting' => fn () => $request->user()?->isStaff()
                ? app(BookingRequests::class)->waitingCount()
                : null,
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
