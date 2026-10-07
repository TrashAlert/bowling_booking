import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Settings/BrandingController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/branding';

/**
 * Where an admin sets the business name and logo shown on every page, and
 * the currency symbol and time zone the venue works in.
 */
export default function Branding({
    businessName,
    businessLogoUrl,
    maxLogoKilobytes,
    contact,
    venue,
    timezones,
}: {
    businessName: string;
    // The logo in use now, or null when the business has none.
    businessLogoUrl: string | null;
    maxLogoKilobytes: number;
    // How customers can reach the business; each is null until filled in.
    contact: {
        phone: string | null;
        address: string | null;
        mapUrl: string | null;
    };
    venue: { currencySymbol: string; timezone: string };
    // Every time zone that can be picked, e.g. "Asia/Kuala_Lumpur".
    timezones: string[];
}) {
    const [removeLogo, setRemoveLogo] = useState(false);

    return (
        <>
            <Head title="Business settings" />

            <h1 className="sr-only">Business settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Business"
                    description="The name, logo and contact details customers see, and the currency and time zone the venue works in"
                />

                <Form
                    {...BrandingController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="business-name">
                                    Business name
                                </Label>

                                <Input
                                    id="business-name"
                                    name="name"
                                    defaultValue={businessName}
                                    maxLength={60}
                                    required
                                    autoComplete="organization"
                                    className="mt-1 block w-full"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="business-logo">Logo</Label>

                                {businessLogoUrl ? (
                                    <div className="flex items-center gap-4">
                                        <img
                                            src={businessLogoUrl}
                                            alt="The logo in use now"
                                            className="size-16 rounded-md border bg-background object-contain p-1"
                                        />
                                        <input
                                            type="hidden"
                                            name="remove_logo"
                                            value={removeLogo ? '1' : '0'}
                                        />
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="remove-logo"
                                                checked={removeLogo}
                                                onCheckedChange={(checked) =>
                                                    setRemoveLogo(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="remove-logo"
                                                className="font-normal"
                                            >
                                                Remove this logo
                                            </Label>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No logo yet. Only the name is shown.
                                    </p>
                                )}

                                <Input
                                    id="business-logo"
                                    name="logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    disabled={removeLogo}
                                    className="mt-1 block w-full"
                                />

                                <p className="text-sm text-muted-foreground">
                                    {businessLogoUrl
                                        ? 'Choose a file to replace it. '
                                        : ''}
                                    PNG, JPG or WebP, up to{' '}
                                    {Math.round(maxLogoKilobytes / 1024)} MB. A
                                    square picture with a clear background works
                                    best. It is shown on the front page, the
                                    customer pages, the staff sidebar and the
                                    login page.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.logo}
                                />
                            </div>

                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    Contact details
                                </legend>
                                <p className="text-sm text-muted-foreground">
                                    Shown at the bottom of the customer pages.
                                    Leave any of them empty to hide it.
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="contact-phone">
                                        Phone number
                                    </Label>
                                    <Input
                                        id="contact-phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={contact.phone ?? ''}
                                        maxLength={30}
                                        autoComplete="off"
                                        placeholder="+60 3-1234 5678"
                                        className="block w-full"
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="contact-address">
                                        Address
                                    </Label>
                                    <textarea
                                        id="contact-address"
                                        name="address"
                                        defaultValue={contact.address ?? ''}
                                        maxLength={200}
                                        rows={2}
                                        placeholder={
                                            'Level 3, Example Mall\n50000 Kuala Lumpur'
                                        }
                                        className="block w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                    />
                                    <InputError message={errors.address} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="contact-map">
                                        Map link
                                    </Label>
                                    <Input
                                        id="contact-map"
                                        name="map_url"
                                        type="url"
                                        defaultValue={contact.mapUrl ?? ''}
                                        maxLength={500}
                                        autoComplete="off"
                                        placeholder="https://maps.app.goo.gl/…"
                                        className="block w-full"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Open your venue in a maps app, choose
                                        Share, and paste the link here.
                                    </p>
                                    <InputError message={errors.map_url} />
                                </div>
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="currency-symbol">
                                    Currency symbol
                                </Label>

                                <Input
                                    id="currency-symbol"
                                    name="currency_symbol"
                                    defaultValue={venue.currencySymbol}
                                    maxLength={5}
                                    required
                                    autoComplete="off"
                                    className="mt-1 block w-24"
                                />

                                <p className="text-sm text-muted-foreground">
                                    Shown in front of amounts of money, such as
                                    the waitlist deposit.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.currency_symbol}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="venue-timezone">
                                    Time zone
                                </Label>

                                <select
                                    id="venue-timezone"
                                    name="timezone"
                                    defaultValue={venue.timezone}
                                    required
                                    className="mt-1 h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                >
                                    {timezones.map((timezone) => (
                                        <option key={timezone} value={timezone}>
                                            {timezone.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>

                                <p className="text-sm text-muted-foreground">
                                    The opening hours, and the times customers
                                    ask to be called, are clock times in this
                                    zone. Changing it shifts when the venue
                                    counts as open. Bookings already made keep
                                    their real time.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.timezone}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save</Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Branding.layout = {
    breadcrumbs: [
        {
            title: 'Business settings',
            href: edit(),
        },
    ],
};
