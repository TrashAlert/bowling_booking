import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import FrontPageController from '@/actions/App/Http/Controllers/Settings/FrontPageController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatShortCalendarDate } from '@/lib/format';
import { edit } from '@/routes/front-page';

type TextName =
    | 'tagline'
    | 'waitlist_title'
    | 'waitlist_text'
    | 'waitlist_button'
    | 'reserve_title'
    | 'reserve_text'
    | 'reserve_button';

// The texts in the order the front page shows them, under the part of the
// page each belongs to. A long one gets a box of several lines.
const groups: {
    title: string;
    texts: { name: TextName; label: string; long?: boolean }[];
}[] = [
    {
        title: 'Under the business name',
        texts: [{ name: 'tagline', label: 'Line under the name' }],
    },
    {
        title: 'Waitlist card',
        texts: [
            { name: 'waitlist_title', label: 'Title' },
            { name: 'waitlist_text', label: 'Description', long: true },
            { name: 'waitlist_button', label: 'Button' },
        ],
    },
    {
        title: 'Reservation card',
        texts: [
            { name: 'reserve_title', label: 'Title' },
            { name: 'reserve_text', label: 'Description', long: true },
            { name: 'reserve_button', label: 'Button' },
        ],
    },
];

/**
 * Where an admin sets the front page's cover photo and writes its words. A
 * text left empty keeps the plain words, shown in grey in its box.
 */
export default function FrontPageSettings({
    texts,
    defaults,
    limits,
    coverUrl,
    maxCoverKilobytes,
    showOpeningHours,
    openingHoursSet,
    announcement,
    maxAnnouncementLength,
    today,
}: {
    // What an admin has written; null where the plain words are used.
    texts: Record<TextName, string | null>;
    defaults: Record<TextName, string>;
    limits: Record<TextName, number>;
    // The cover photo in use now, or null when there is none.
    coverUrl: string | null;
    maxCoverKilobytes: number;
    showOpeningHours: boolean;
    // Whether any opening hours have been saved to list.
    openingHoursSet: boolean;
    // The banner across the customer pages, and the last day to show it as
    // a date on the venue's clock; each null when not set.
    announcement: { message: string | null; until: string | null };
    maxAnnouncementLength: number;
    // Today on the venue's clock, e.g. "2026-04-08".
    today: string;
}) {
    const [removeCover, setRemoveCover] = useState(false);
    const [showHours, setShowHours] = useState(showOpeningHours);

    return (
        <>
            <Head title="Front page settings" />

            <h1 className="sr-only">Front page settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Front page"
                    description="The photo and the words customers see on the front page"
                />

                <Form
                    {...FrontPageController.update.form()}
                    options={{ preserveScroll: true }}
                    // A saved photo is in use, so the file box and the
                    // remove tick start empty again.
                    resetOnSuccess={['cover']}
                    onSuccess={() => setRemoveCover(false)}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="front-cover">Cover photo</Label>

                                {coverUrl ? (
                                    <div className="flex flex-wrap items-center gap-4">
                                        <img
                                            src={coverUrl}
                                            alt="The cover photo in use now"
                                            className="aspect-video w-48 rounded-md border object-cover"
                                        />
                                        <input
                                            type="hidden"
                                            name="remove_cover"
                                            value={removeCover ? '1' : '0'}
                                        />
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="remove-cover"
                                                checked={removeCover}
                                                onCheckedChange={(checked) =>
                                                    setRemoveCover(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="remove-cover"
                                                className="font-normal"
                                            >
                                                Remove this photo
                                            </Label>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No cover photo yet. The front page shows
                                        the name on a plain background.
                                    </p>
                                )}

                                <Input
                                    id="front-cover"
                                    name="cover"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    disabled={removeCover}
                                />

                                <p className="text-sm text-muted-foreground">
                                    {coverUrl
                                        ? 'Choose a file to replace it. '
                                        : ''}
                                    PNG, JPG or WebP, up to{' '}
                                    {Math.round(maxCoverKilobytes / 1024)} MB. A
                                    wide photo of the venue works best, about
                                    1600 pixels across. It is darkened behind
                                    the name so the words stay readable.
                                </p>

                                <InputError message={errors.cover} />
                            </div>

                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    Announcement
                                </legend>
                                <p className="text-sm text-muted-foreground">
                                    {announcement.message === null
                                        ? 'Nothing is shown now. Write a message to put a banner across the top of every customer page.'
                                        : announcement.until !== null &&
                                            announcement.until < today
                                          ? `Its last day, ${formatShortCalendarDate(announcement.until)}, has passed, so it is no longer shown. Empty the message, or pick a new last day.`
                                          : 'Shown now across the top of every customer page.'}
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="front-announcement">
                                        Message
                                    </Label>
                                    <textarea
                                        id="front-announcement"
                                        name="announcement"
                                        defaultValue={
                                            announcement.message ?? ''
                                        }
                                        maxLength={maxAnnouncementLength}
                                        rows={2}
                                        placeholder="Closed for Hari Raya, 10–12 April. See you on the 13th!"
                                        className="block w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                    />
                                    <InputError message={errors.announcement} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="front-announcement-until">
                                        Show until (optional)
                                    </Label>
                                    <Input
                                        id="front-announcement-until"
                                        name="announcement_until"
                                        type="date"
                                        min={today}
                                        defaultValue={announcement.until ?? ''}
                                        className="w-44"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        It is shown through the end of that day,
                                        then hides itself. Leave it empty to
                                        show it until you empty the message.
                                    </p>
                                    <InputError
                                        message={errors.announcement_until}
                                    />
                                </div>
                            </fieldset>

                            {groups.map((group) => (
                                <fieldset
                                    key={group.title}
                                    className="space-y-4 rounded-lg border p-4"
                                >
                                    <legend className="px-1 text-sm font-medium">
                                        {group.title}
                                    </legend>

                                    {group.texts.map(
                                        ({ name, label, long }) => (
                                            <div
                                                key={name}
                                                className="grid gap-2"
                                            >
                                                <Label
                                                    htmlFor={`front-${name}`}
                                                >
                                                    {label}
                                                </Label>
                                                {long ? (
                                                    <textarea
                                                        id={`front-${name}`}
                                                        name={name}
                                                        defaultValue={
                                                            texts[name] ?? ''
                                                        }
                                                        placeholder={
                                                            defaults[name]
                                                        }
                                                        maxLength={limits[name]}
                                                        rows={2}
                                                        className="block w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                                    />
                                                ) : (
                                                    <Input
                                                        id={`front-${name}`}
                                                        name={name}
                                                        defaultValue={
                                                            texts[name] ?? ''
                                                        }
                                                        placeholder={
                                                            defaults[name]
                                                        }
                                                        maxLength={limits[name]}
                                                        autoComplete="off"
                                                    />
                                                )}
                                                <InputError
                                                    message={errors[name]}
                                                />
                                            </div>
                                        ),
                                    )}
                                </fieldset>
                            ))}

                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="show_opening_hours"
                                    value={showHours ? '1' : '0'}
                                />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="show-opening-hours"
                                        checked={showHours}
                                        onCheckedChange={(checked) =>
                                            setShowHours(checked === true)
                                        }
                                    />
                                    <Label htmlFor="show-opening-hours">
                                        Show the opening hours on the front page
                                    </Label>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {openingHoursSet
                                        ? "Shown under the two cards as one line with today's hours, which customers can tap to see the whole week."
                                        : 'No opening hours are set yet, so nothing is listed. Set them under Opening hours.'}
                                </p>
                            </div>

                            <p className="text-sm text-muted-foreground">
                                Leave a box empty for the words shown in grey.
                                The small notes on the cards, such as when a
                                deposit is asked for, follow the settings and
                                can't be changed here.
                            </p>

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

FrontPageSettings.layout = {
    breadcrumbs: [
        {
            title: 'Front page settings',
            href: edit(),
        },
    ],
};
