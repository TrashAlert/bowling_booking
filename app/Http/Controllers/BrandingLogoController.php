<?php

namespace App\Http\Controllers;

use App\Services\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingLogoController extends Controller
{
    /**
     * Serve the business logo to anyone: it is on the public pages. Its
     * address changes with each new logo, so browsers may keep it a while.
     */
    public function __invoke(Branding $branding): StreamedResponse
    {
        $path = $branding->logoPath();

        abort_if($path === null || ! Storage::disk($branding->disk())->exists($path), 404);

        return Storage::disk($branding->disk())->response($path, headers: [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
