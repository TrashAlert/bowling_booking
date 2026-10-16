<?php

namespace App\Http\Controllers;

use App\Services\FrontPage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FrontPageCoverController extends Controller
{
    /**
     * Serve the front page's cover photo to anyone: it is on the public
     * front page. Its address changes with each new photo, so browsers may
     * keep it a while.
     */
    public function __invoke(FrontPage $frontPage): StreamedResponse
    {
        $path = $frontPage->coverPath();

        abort_if($path === null || ! Storage::disk($frontPage->disk())->exists($path), 404);

        return Storage::disk($frontPage->disk())->response($path, headers: [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
