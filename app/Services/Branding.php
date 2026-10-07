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
            ? Setting::query()->where('key', $key)->delete()
            : Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The name, logo address and contact details together, for sharing with
     * every page.
     *
     * @return array{name: string, logoUrl: string|null, contact: array{phone: string|null, address: string|null, mapUrl: string|null}}
     */
    public function forPages(): array
    {
        $saved = $this->saved();

        return [
            'name' => $saved->get(self::NAME) ?? config('app.name'),
            'logoUrl' => $this->urlFor($saved->get(self::LOGO_PATH)),
            'contact' => $this->contactFrom($saved),
        ];
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

        Setting::query()->where('key', self::LOGO_PATH)->delete();

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
     * What has been saved, keyed by setting name.
     *
     * @return Collection<string, mixed>
     */
    private function saved(): Collection
    {
        return Setting::query()
            ->whereIn('key', [self::NAME, self::LOGO_PATH, ...array_values(self::CONTACT)])
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
