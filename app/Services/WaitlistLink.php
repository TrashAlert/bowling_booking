<?php

namespace App\Services;

use App\Models\WaitlistEntry;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The link to a party's own page about its place in the line, and a QR code
 * of it for a walk-in to scan at the counter.
 *
 * The link is built on the address customers reach the app at
 * (bowling.public_url), not the one staff opened it on: the counter is on the
 * venue's own network, which a customer's phone on mobile data can't reach.
 */
class WaitlistLink
{
    // How big the code is drawn, in pixels, and the quiet border around it,
    // in squares of the code, which phone cameras need to find it.
    private const SIZE = 320;

    private const MARGIN = 2;

    public function url(WaitlistEntry $entry): string
    {
        $base = config('bowling.public_url') ?: request()->getSchemeAndHttpHost();

        return rtrim($base, '/').route('waitlist.show', ['entry' => $entry->token], absolute: false);
    }

    /**
     * The QR code of the link as an SVG image: black on white, which every
     * phone camera reads, in light mode or dark.
     */
    public function qrCodeSvg(WaitlistEntry $entry): string
    {
        return (new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd,
        )))->writeString($this->url($entry));
    }
}
