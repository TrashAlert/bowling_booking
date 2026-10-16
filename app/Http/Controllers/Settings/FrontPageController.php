<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FrontPageUpdateRequest;
use App\Services\FrontPage;
use App\Services\OpeningHours;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FrontPageController extends Controller
{
    /**
     * Show the front page's words, what an admin has written and the
     * defaults used for any left empty, and its cover photo.
     */
    public function edit(FrontPage $frontPage, OpeningHours $hours): Response
    {
        return Inertia::render('settings/front-page', [
            'texts' => $frontPage->saved(),
            'defaults' => array_map(fn (array $text) => $text['default'], FrontPage::TEXTS),
            'limits' => array_map(fn (array $text) => $text['max'], FrontPage::TEXTS),
            'coverUrl' => $frontPage->coverUrl(),
            'maxCoverKilobytes' => FrontPageUpdateRequest::MAX_COVER_KILOBYTES,
            'showOpeningHours' => $frontPage->showsOpeningHours(),
            // Nothing is listed until hours are saved under Opening hours.
            'openingHoursSet' => $hours->isSet(),
            'announcement' => $frontPage->announcement(),
            'maxAnnouncementLength' => FrontPage::ANNOUNCEMENT_LENGTH,
            // Today on the venue's clock: the earliest last day to pick, and
            // what tells whether an announcement has ended.
            'today' => now($hours->timezone())->toDateString(),
        ]);
    }

    /**
     * Save the front page's words, and a new cover photo or the removal of
     * the old one.
     */
    public function update(FrontPageUpdateRequest $request, FrontPage $frontPage): RedirectResponse
    {
        $frontPage->save($request->safe()->except(['cover', 'remove_cover', 'show_opening_hours', 'announcement', 'announcement_until']));

        if ($request->safe()->has('announcement')) {
            $frontPage->announce($request->safe()->input('announcement'), $request->safe()->input('announcement_until'));
        }

        if ($request->safe()->has('show_opening_hours')) {
            $frontPage->showOpeningHours($request->boolean('show_opening_hours'));
        }

        if ($request->hasFile('cover')) {
            $frontPage->useCover($request->file('cover'));
        } elseif ($request->boolean('remove_cover')) {
            $frontPage->removeCover();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Front page saved.')]);

        return to_route('front-page.edit');
    }
}
