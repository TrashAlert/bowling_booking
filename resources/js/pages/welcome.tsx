import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { CalendarClock, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { formatTime, formatWhen } from '@/lib/format';
import type { OpeningStatus } from '@/types';

// How often the page asks again how many lanes are free.
const POLL_INTERVAL_MS = 30_000;

// What customers can do from this page. Joining the waitlist works, with a
// stand-in for the deposit payment. Reserving sends a request that staff
// confirm by phone.
const actions = [
    {
        icon: Users,
        title: 'Join the waitlist',
        description:
            'Walking in? Add your group to the line and we will call you when a lane is ready.',
        action: 'Join the waitlist',
        badge: 'Trial',
        note: 'A deposit is only needed when every lane is busy.',
        // Said instead while the venue has the deposit turned off.
        noteWithoutDeposit: 'No deposit is needed to join.',
        href: join(),
    },
    {
        icon: CalendarClock,
        title: 'Reserve a lane',
        description:
            'Planning ahead? Pick a date and time and we will keep a lane for you.',
        action: 'Make a reservation',
        badge: null,
        note: 'Send us a request and we will call you to confirm.',
        noteWithoutDeposit: null,
        href: reserve(),
    },
];

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
}: LaneCount & { lanesOpen: boolean; opening: OpeningStatus }) {
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
                    : 'Join the waitlist, or reserve a lane for later.'}
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
}: {
    lanes: LaneCount;
    // False while every lane is out of order.
    lanesOpen: boolean;
    // False while the deposit for joining the waitlist online is turned off.
    depositAsked: boolean;
    opening: OpeningStatus;
}) {
    const { name } = usePage().props;

    usePoll(POLL_INTERVAL_MS, {
        only: ['lanes', 'lanesOpen', 'depositAsked', 'opening'],
    });

    return (
        <>
            <Head title="Welcome" />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-4xl items-center justify-between gap-4 p-4 sm:p-6">
                    <span className="truncate font-semibold tracking-tight">
                        {name}
                    </span>
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center gap-10 p-4 sm:p-6">
                    <div className="text-center">
                        <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            {name}
                        </h1>
                        <p className="mt-3 text-lg text-muted-foreground">
                            Come and bowl. Walk in, or book a lane ahead.
                        </p>
                        <LanesFree
                            {...lanes}
                            lanesOpen={lanesOpen}
                            opening={opening}
                        />
                    </div>

                    {/* Each card's heading, note and button sit on rows shared
                        with the card beside it, so the two always line up. */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {actions.map((item) => (
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
                                        {item.badge && (
                                            <Badge variant="secondary">
                                                {item.badge}
                                            </Badge>
                                        )}
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
                </main>

                <footer className="p-4 text-center text-sm text-muted-foreground sm:p-6">
                    © {new Date().getFullYear()} {name}
                </footer>
            </div>
        </>
    );
}
