import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Clock, Users } from 'lucide-react';
import AnnouncementBanner from '@/components/announcement-banner';
import BrandColors from '@/components/brand-colors';
import BusinessLogo from '@/components/business-logo';
import ContactDetails from '@/components/contact-details';
import DemoBanner from '@/components/demo-banner';
import ThemeToggle from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { reserve } from '@/routes';
import { join } from '@/routes/waitlist';
import {
    formatTime,
    formatTimeOfDay,
    formatWeekday,
    formatWhen,
} from '@/lib/format';
import type {
    DayHours,
    FrontPageOpeningHours,
    FrontPageTexts,
    OnlineWaitlistStatus,
    OpeningStatus,
} from '@/types';

// How often the page asks again how many lanes are free.
const POLL_INTERVAL_MS = 30_000;

// What customers can do from this page. Joining the waitlist works, with a
// stand-in for the deposit payment. Reserving sends a request that staff
// confirm by phone. The titles, descriptions and buttons are the words an
// admin set under Settings, Front page.
const actions = (texts: FrontPageTexts) => [
    {
        // Left off the page while an admin has closed joining online under
        // Settings, Online waitlist.
        closable: true,
        icon: Users,
        title: texts.waitlistTitle,
        description: texts.waitlistText,
        action: texts.waitlistButton,
        note: 'A deposit is only needed when every lane is busy.',
        // Said instead while the venue has the deposit turned off.
        noteWithoutDeposit: 'No deposit is needed to join.',
        href: join(),
    },
    {
        closable: false,
        icon: CalendarClock,
        title: texts.reserveTitle,
        description: texts.reserveText,
        action: texts.reserveButton,
        note: 'Send us a request and we will call you to confirm.',
        noteWithoutDeposit: null,
        href: reserve(),
    },
];

/**
 * A day's hours, e.g. "10:00 AM – 11:00 PM", or null when it is closed. A day
 * that runs past midnight reads e.g. "10:00 AM – 2:00 AM".
 */
function hoursOf(day: DayHours | undefined): string | null {
    return day?.opens && day.closes
        ? `${formatTimeOfDay(day.opens)} – ${formatTimeOfDay(day.closes)}`
        : null;
}

/**
 * The opening hours in one line, today's, which opens to show the whole
 * week with today in bold.
 */
function OpeningHoursList({ days, today }: FrontPageOpeningHours) {
    const todays = hoursOf(days.find((day) => day.weekday === today));

    return (
        <Collapsible className="-mt-4 text-center text-sm">
            <CollapsibleTrigger className="group inline-flex flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-md px-3 py-1.5 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <Clock aria-hidden className="size-4 shrink-0" />
                <span>
                    {todays ? (
                        <>
                            Open today{' '}
                            <span className="font-medium text-foreground tabular-nums">
                                {todays}
                            </span>
                        </>
                    ) : (
                        <span className="font-medium text-foreground">
                            Closed today
                        </span>
                    )}
                </span>
                <span aria-hidden>·</span>
                <span className="inline-flex items-center gap-1 underline-offset-4 group-hover:underline">
                    All opening hours
                    <ChevronDown
                        aria-hidden
                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                    />
                </span>
            </CollapsibleTrigger>

            <CollapsibleContent>
                <div className="mx-auto mt-2 w-fit rounded-xl border px-5 py-3 text-left">
                    <dl className="grid grid-cols-[auto_auto] gap-x-6 gap-y-0.5">
                        {days.map((day) => {
                            const isToday = day.weekday === today;

                            return (
                                <div
                                    key={day.weekday}
                                    className={cn(
                                        'contents',
                                        isToday
                                            ? 'font-semibold text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <dt>
                                        {formatWeekday(day.weekday, true)}
                                        {isToday && (
                                            <span className="sr-only">
                                                {' '}
                                                (today)
                                            </span>
                                        )}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {hoursOf(day) ?? 'Closed'}
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

type YourPlace = {
    name: string;
    status: 'waiting' | 'called';
    position: number;
    // The group's own page.
    url: string;
};

/**
 * The groups this phone is following in the waitlist, so a customer who
 * closed the page can get back to it. Called groups are told to go to the
 * counter.
 */
function YourPlaces({ places }: { places: YourPlace[] }) {
    return (
        <section aria-label="Your place in line" className="grid gap-3">
            {places.map((place) => {
                const called = place.status === 'called';

                return (
                    <div
                        key={place.url}
                        className={cn(
                            'flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4',
                            called &&
                                'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10',
                        )}
                    >
                        <div className="min-w-0">
                            <p className="font-medium">
                                {called
                                    ? `It is your turn, ${place.name}`
                                    : `You are in line, ${place.name}`}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {called
                                    ? 'Go to the counter now.'
                                    : `Number ${place.position} in line.`}
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={place.url}>View your place</Link>
                        </Button>
                    </div>
                );
            })}
        </section>
    );
}

type LaneCount = {
    // Lanes free right now, after the ones the waitlist is about to take.
    free: number;
    total: number;
    // Groups in the waitlist, whether still waiting or already called.
    waiting: number;
};

/**
 * How many lanes are left for someone arriving now, so a customer can choose
 * between walking in and booking ahead. Shown once the venue has lanes. While
 * every lane is out of order it says so instead, since nobody can play.
 */
function LanesFree({
    free,
    total,
    waiting,
    lanesOpen,
    opening,
    onlineOpen,
}: LaneCount & {
    lanesOpen: boolean;
    opening: OpeningStatus;
    // Whether groups can join the waitlist online, or only at the counter.
    onlineOpen: boolean;
}) {
    if (!opening.isOpen) {
        return (
            <div className="mx-auto mt-6 w-fit rounded-xl border px-5 py-3">
                <p className="flex items-center justify-center gap-2 font-medium">
                    <span
                        aria-hidden
                        className="size-2.5 rounded-full bg-muted-foreground"
                    />
                    We are closed right now
                </p>
                <p className="text-sm text-muted-foreground">
                    {opening.opensAt
                        ? `We open again ${formatWhen(opening.opensAt)}.`
                        : 'Please check back later.'}
                </p>
            </div>
        );
    }

    if (total === 0) {
        return null;
    }

    if (!lanesOpen) {
        return (
            <div className="mx-auto mt-6 w-fit rounded-xl border px-5 py-3">
                <p className="flex items-center justify-center gap-2 font-medium">
                    <span
                        aria-hidden
                        className="size-2.5 rounded-full bg-amber-500"
                    />
                    All our lanes are closed for maintenance right now
                </p>
                <p className="text-sm text-muted-foreground">
                    Please check back later, or reserve a lane for another day.
                </p>
            </div>
        );
    }

    const groups = `${waiting} ${waiting === 1 ? 'group' : 'groups'}`;

    return (
        <div className="mx-auto mt-6 w-fit rounded-xl border px-5 py-3">
            <p className="flex items-center justify-center gap-2 font-medium">
                <span
                    aria-hidden
                    className={cn(
                        'size-2.5 rounded-full',
                        free > 0 ? 'bg-emerald-500' : 'bg-amber-500',
                    )}
                />
                {free > 0
                    ? `${free} of ${total} ${total === 1 ? 'lane' : 'lanes'} free right now`
                    : 'No lane is free right now'}
            </p>
            {waiting > 0 && (
                <p className="text-sm">
                    {free > 0
                        ? `That is after the ${groups} already in the waitlist.`
                        : `${groups} in the waitlist.`}
                </p>
            )}
            <p className="text-sm text-muted-foreground">
                {free > 0
                    ? 'Walk in and play, or reserve a lane for later.'
                    : onlineOpen
                      ? 'Join the waitlist, or reserve a lane for later.'
                      : 'Join the waitlist at the counter, or reserve a lane for later.'}
            </p>
            {opening.closesAt && (
                <p className="text-sm text-muted-foreground">
                    Open until {formatTime(opening.closesAt)}.
                </p>
            )}
        </div>
    );
}

export default function Welcome({
    lanes,
    lanesOpen,
    depositAsked,
    opening,
    onlineWaitlist,
    texts,
    coverUrl,
    openingHours,
    yourPlaces,
}: {
    lanes: LaneCount;
    // False while every lane is out of order.
    lanesOpen: boolean;
    // False while the deposit for joining the waitlist online is turned off.
    depositAsked: boolean;
    opening: OpeningStatus;
    // Whether customers can join the waitlist online at all.
    onlineWaitlist: OnlineWaitlistStatus;
    // The words an admin set for this page.
    texts: FrontPageTexts;
    // The photo behind the business name; null when there is none.
    coverUrl: string | null;
    // The week's hours; null while an admin hides them or none are set.
    openingHours: FrontPageOpeningHours | null;
    // The groups this phone has opened the page of that are still in line.
    yourPlaces: YourPlace[];
}) {
    const { name } = usePage().props;

    usePoll(POLL_INTERVAL_MS, {
        only: [
            'lanes',
            'lanesOpen',
            'depositAsked',
            'opening',
            'onlineWaitlist',
            'yourPlaces',
        ],
    });

    // The waitlist card is left off while joining online is closed: walk-ins
    // still join at the counter.
    const cards = actions(texts).filter(
        (item) => !item.closable || onlineWaitlist.open,
    );

    return (
        <>
            <Head title="Welcome" />
            <BrandColors />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <DemoBanner />
                <AnnouncementBanner />
                <header className="mx-auto flex w-full max-w-4xl items-center justify-between gap-4 p-4 sm:p-6">
                    <span className="flex items-center gap-2 truncate font-semibold tracking-tight">
                        <BusinessLogo className="size-6 shrink-0 rounded" />
                        {name}
                    </span>
                    <ThemeToggle />
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center gap-10 p-4 sm:p-6">
                    {yourPlaces.length > 0 && (
                        <YourPlaces places={yourPlaces} />
                    )}

                    <div className="text-center">
                        {coverUrl ? (
                            // The photo is darkened so the white words read
                            // on any picture.
                            <div className="relative overflow-hidden rounded-2xl px-6 py-16 text-white sm:py-24">
                                <img
                                    src={coverUrl}
                                    alt=""
                                    className="absolute inset-0 size-full object-cover"
                                />
                                <div
                                    aria-hidden
                                    className="absolute inset-0 bg-black/55"
                                />
                                <div className="relative">
                                    <BusinessLogo className="mx-auto mb-4 size-24 rounded-xl" />
                                    <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                                        {name}
                                    </h1>
                                    <p className="mt-3 text-lg text-white/85">
                                        {texts.tagline}
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <>
                                <BusinessLogo className="mx-auto mb-4 size-24 rounded-xl" />
                                <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                                    {name}
                                </h1>
                                <p className="mt-3 text-lg text-muted-foreground">
                                    {texts.tagline}
                                </p>
                            </>
                        )}
                        <LanesFree
                            {...lanes}
                            lanesOpen={lanesOpen}
                            opening={opening}
                            onlineOpen={onlineWaitlist.open}
                        />
                    </div>

                    {/* Each card's heading, note and button sit on rows shared
                        with the card beside it, so the two always line up. A
                        card on its own sits in the middle. */}
                    <div
                        className={cn(
                            'grid gap-4',
                            cards.length > 1
                                ? 'sm:grid-cols-2'
                                : 'mx-auto w-full max-w-md',
                        )}
                    >
                        {cards.map((item) => (
                            <Card
                                key={item.title}
                                className="row-span-3 grid grid-rows-subgrid"
                            >
                                <CardHeader>
                                    <item.icon
                                        aria-hidden
                                        className="mb-2 size-6 text-muted-foreground"
                                    />
                                    <CardTitle className="flex min-h-6 items-center gap-2">
                                        {item.title}
                                    </CardTitle>
                                    <CardDescription>
                                        {item.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="text-sm text-muted-foreground">
                                    {depositAsked
                                        ? item.note
                                        : (item.noteWithoutDeposit ??
                                          item.note)}
                                </CardContent>
                                <CardFooter>
                                    <Button className="w-full" asChild>
                                        <Link href={item.href}>
                                            {item.action}
                                        </Link>
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>

                    {openingHours && <OpeningHoursList {...openingHours} />}
                </main>

                <footer className="space-y-3 p-4 text-center text-sm text-muted-foreground sm:p-6">
                    <ContactDetails />
                    <p>
                        © {new Date().getFullYear()} {name}
                    </p>
                </footer>
            </div>
        </>
    );
}
