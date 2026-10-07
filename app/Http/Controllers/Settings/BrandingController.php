<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BrandingUpdateRequest;
use App\Services\Branding;
use App\Services\VenueRules;
use DateTimeZone;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BrandingController extends Controller
{
    /**
     * Show the business name and logo settings page.
     */
    public function edit(Branding $branding): Response
    {
        return Inertia::render('settings/branding', [
            'businessName' => $branding->name(),
            'businessLogoUrl' => $branding->logoUrl(),
            'maxLogoKilobytes' => BrandingUpdateRequest::MAX_LOGO_KILOBYTES,
            'contact' => $branding->contact(),
            'venue' => [
                'currencySymbol' => config('bowling.currency_symbol'),
                'timezone' => config('bowling.timezone'),
            ],
            // Every time zone that can be picked, e.g. "Asia/Kuala_Lumpur".
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Save the business name, a new logo or the removal of the old one, the
     * contact details, and the currency symbol and time zone.
     *
     * The page is loaded afresh afterwards, not just updated, because the
     * name is also in the browser tab's title, which only a fresh load sets.
     */
    public function update(BrandingUpdateRequest $request, Branding $branding, VenueRules $rules): HttpResponse
    {
        $branding->rename($request->string('name')->toString());

        foreach ($request->safe()->only(['currency_symbol', 'timezone']) as $rule => $value) {
            $rules->save($rule, $value);
        }

        foreach ($request->safe()->only(['phone', 'address', 'map_url']) as $detail => $value) {
            $branding->setContact($detail, $value);
        }

        if ($request->hasFile('logo')) {
            $branding->useLogo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $branding->removeLogo();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Business details saved.')]);

        return Inertia::location(route('branding.edit'));
    }
}
