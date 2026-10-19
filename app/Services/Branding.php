<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * How the business presents itself on every page: its name, its logo and
 * how to reach it, as an admin sets them under Settings. Until a name is
 * saved it is the APP_NAME from .env, and until a logo is uploaded there is
 * none. Contact details are only shown once they are filled in.
 */
class Branding
{
    private const NAME = 'business.name';

    private const LOGO_PATH = 'business.logo_path';

    private const BRAND_COLOR = 'business.brand_color';

    // A colour as the settings page sends it, e.g. "#1d4ed8".
    public const COLOR_PATTERN = '/\A#[0-9a-fA-F]{6}\z/';

    // The dark text put on a light brand colour: the near-black of the pages.
    private const DARK_TEXT = '#0a0a0a';

    /**
     * The contact details customers are shown, by the name the settings form
     * gives each and where it is kept.
     *
     * @var array<string, string>
     */
    private const CONTACT = [
        'phone' => 'business.contact_phone',
        'address' => 'business.contact_address',
        'map_url' => 'business.contact_map_url',
    ];

    /**
     * The social media accounts customers are shown, by network and where
     * each is kept. Each is kept in its plain form: the WhatsApp number as
     * digits, the Instagram and TikTok names without "@", and the part of a
     * Facebook address after "facebook.com/".
     *
     * @var array<string, string>
     */
    public const SOCIAL = [
        'whatsapp' => 'business.social_whatsapp',
        'instagram' => 'business.social_instagram',
        'facebook' => 'business.social_facebook',
        'tiktok' => 'business.social_tiktok',
    ];

    // Logos are kept out of the public folder and served by the app, so
    // nothing has to be linked or exposed for them to show.
    private const DISK = 'local';

    public function name(): string
    {
        return $this->saved()->get(self::NAME) ?? config('app.name');
    }

    /**
     * The address pages load the logo from, or null when there is none. It
     * changes with every new logo, so browsers never show a stale one.
     */
    public function logoUrl(): ?string
    {
        return $this->urlFor($this->logoPath());
    }

    /**
     * Where the logo file is kept on disk, or null when there is none.
     */
    public function logoPath(): ?string
    {
        return $this->saved()->get(self::LOGO_PATH);
    }

    /**
     * How customers can reach the business. Each is null until filled in.
     *
     * @return array{phone: string|null, address: string|null, mapUrl: string|null}
     */
    public function contact(): array
    {
        return $this->contactFrom($this->saved());
    }

    /**
     * Set one contact detail ("phone", "address" or "map_url"), or clear it
     * by giving nothing.
     *
     * @throws InvalidArgumentException when it is not one of the three.
     */
    public function setContact(string $detail, ?string $value): void
    {
        $key = self::CONTACT[$detail] ?? throw new InvalidArgumentException("There is no contact detail called [{$detail}].");

        blank($value)
            ? Setting::forget($key)
            : Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The social media accounts in their plain form, each null until filled
     * in.
     *
     * @return array{whatsapp: string|null, instagram: string|null, facebook: string|null, tiktok: string|null}
     */
    public function social(): array
    {
        $saved = $this->saved();

        return array_map(fn (string $key) => $saved->get($key), self::SOCIAL);
    }

    /**
     * Set one social media account from what an admin typed, or clear it by
     * giving nothing.
     *
     * @throws InvalidArgumentException when it is not one of the four, or not an account on it.
     */
    public function setSocial(string $network, ?string $typed): void
    {
        $key = self::SOCIAL[$network] ?? throw new InvalidArgumentException("There is no social network called [{$network}].");

        if (blank($typed)) {
            Setting::forget($key);

            return;
        }

        $plain = self::plainSocial($network, $typed) ?? throw new InvalidArgumentException("[{$typed}] is not an account on {$network}.");

        Setting::updateOrCreate(['key' => $key], ['value' => $plain]);
    }

    /**
     * What an admin typed for a social network, in the plain form it is kept
     * in, or null when it is not an account on that network. A number may
     * be written with spaces and dashes, a name with or without "@", and an
     * account's own address may be pasted in place of its name.
     */
    public static function plainSocial(string $network, string $typed): ?string
    {
        $typed = trim($typed);

        if ($network === 'whatsapp') {
            $digits = preg_replace('/\D/', '', $typed);

            return preg_match('/\A\+?[0-9\s\-()]+\z/', $typed) === 1 && strlen($digits) >= 8 && strlen($digits) <= 15
                ? $digits
                : null;
        }

        $hosts = [
            'instagram' => ['instagram.com', 'www.instagram.com'],
            'tiktok' => ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'],
            'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com', 'fb.com'],
        ][$network] ?? [];

        // A pasted address counts only on the network's own site.
        if (preg_match('#\Ahttps?://#i', $typed) === 1) {
            $parts = parse_url($typed);

            if (! in_array(strtolower($parts['host'] ?? ''), $hosts, true)) {
                return null;
            }

            $typed = trim($parts['path'] ?? '', '/');

            if ($network === 'facebook' && isset($parts['query']) && $typed === 'profile.php') {
                $typed .= '?'.$parts['query'];
            }
        }

        $name = ltrim($typed, '@');

        $pattern = match ($network) {
            'instagram' => '/\A[A-Za-z0-9._]{1,30}\z/',
            'tiktok' => '/\A[A-Za-z0-9._]{2,24}\z/',
            // A page name, or a profile without one: "profile.php?id=…".
            'facebook' => '/\A([A-Za-z0-9.\-]{5,50}|profile\.php\?id=[0-9]{5,20})\z/',
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $name) === 1 ? $name : null;
    }

    /**
     * The colour the customer pages use for buttons and highlights, as
     * "#1d4ed8", or null for the plain black and white.
     */
    public function brandColor(): ?string
    {
        return $this->saved()->get(self::BRAND_COLOR);
    }

    /**
     * Set the brand colour, or go back to the plain one by giving nothing.
     *
     * @throws InvalidArgumentException when it is not a colour like "#1d4ed8".
     */
    public function setBrandColor(?string $color): void
    {
        if (blank($color)) {
            Setting::forget(self::BRAND_COLOR);

            return;
        }

        if (preg_match(self::COLOR_PATTERN, $color) !== 1) {
            throw new InvalidArgumentException("[{$color}] is not a colour like #1d4ed8.");
        }

        Setting::updateOrCreate(['key' => self::BRAND_COLOR], ['value' => strtolower($color)]);
    }

    /**
     * The name, logo address, contact details and brand colour together, for
     * sharing with every page. The brand colour comes with the text colour
     * that reads best on it.
     *
     * The social media accounts come as the addresses to open them at.
     *
     * @return array{name: string, logoUrl: string|null, contact: array{phone: string|null, address: string|null, mapUrl: string|null}, social: array{whatsapp: string|null, instagram: string|null, facebook: string|null, tiktok: string|null}, brand: array{color: string, textColor: string}|null}
     */
    public function forPages(): array
    {
        $saved = $this->saved();
        $color = $saved->get(self::BRAND_COLOR);

        return [
            'name' => $saved->get(self::NAME) ?? config('app.name'),
            'logoUrl' => $this->urlFor($saved->get(self::LOGO_PATH)),
            'contact' => $this->contactFrom($saved),
            'social' => array_map(fn (string $key) => $this->socialLink(array_search($key, self::SOCIAL, true), $saved->get($key)), self::SOCIAL),
            'brand' => $color === null ? null : ['color' => $color, 'textColor' => $this->textColorOn($color)],
        ];
    }

    /**
     * White or near-black, whichever stands out more from $color, by the
     * contrast measure of the web's accessibility guidelines (WCAG).
     */
    public function textColorOn(string $color): string
    {
        $luminance = $this->luminance($color);
        $againstWhite = 1.05 / ($luminance + 0.05);
        $againstDark = ($luminance + 0.05) / ($this->luminance(self::DARK_TEXT) + 0.05);

        return $againstWhite >= $againstDark ? '#ffffff' : self::DARK_TEXT;
    }

    public function rename(string $name): void
    {
        Setting::updateOrCreate(['key' => self::NAME], ['value' => $name]);
    }

    /**
     * Take a newly uploaded logo into use and throw the old one away.
     */
    public function useLogo(UploadedFile $logo): void
    {
        $old = $this->logoPath();

        Setting::updateOrCreate(['key' => self::LOGO_PATH], ['value' => $logo->store('branding', self::DISK)]);

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    /**
     * Go back to having no logo.
     */
    public function removeLogo(): void
    {
        $old = $this->logoPath();

        Setting::forget(self::LOGO_PATH);

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    /**
     * The disk the logo file is kept on.
     */
    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * The address a social media account opens at, from its plain form.
     */
    private function socialLink(string $network, ?string $plain): ?string
    {
        return $plain === null ? null : match ($network) {
            'whatsapp' => "https://wa.me/{$plain}",
            'instagram' => "https://www.instagram.com/{$plain}/",
            'facebook' => "https://www.facebook.com/{$plain}",
            'tiktok' => "https://www.tiktok.com/@{$plain}",
        };
    }

    /**
     * How light a colour like "#1d4ed8" looks, from 0 for black to 1 for
     * white, as WCAG measures it.
     */
    private function luminance(string $color): float
    {
        $channels = array_map(function (string $hex) {
            $value = hexdec($hex) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * What has been saved, keyed by setting name.
     *
     * @return Collection<string, mixed>
     */
    private function saved(): Collection
    {
        return Setting::query()
            ->whereIn('key', [self::NAME, self::LOGO_PATH, self::BRAND_COLOR, ...array_values(self::CONTACT), ...array_values(self::SOCIAL)])
            ->pluck('value', 'key');
    }

    /**
     * @param  Collection<string, mixed>  $saved
     * @return array{phone: string|null, address: string|null, mapUrl: string|null}
     */
    private function contactFrom(Collection $saved): array
    {
        return [
            'phone' => $saved->get(self::CONTACT['phone']),
            'address' => $saved->get(self::CONTACT['address']),
            'mapUrl' => $saved->get(self::CONTACT['map_url']),
        ];
    }

    private function urlFor(?string $path): ?string
    {
        return $path === null
            ? null
            : route('branding.logo', ['v' => substr(md5($path), 0, 8)], absolute: false);
    }
}
