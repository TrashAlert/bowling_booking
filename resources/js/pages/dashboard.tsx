import { Head, Link, usePoll } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    CalendarClock,
    CircleCheck,
    Clock,
    Inbox,
    TriangleAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ColumnChart } from '@/components/staff/column-chart';
import { Button } from '@/components/ui/button';
import { useServerClock } from '@/hooks/use-server-clock';
import { toDateInput } from '@/lib/dates';
import {
    formatDate,
    formatDateTick,
    formatHour,
    formatLanes,
    formatMinutes,
    formatShortDate,
    formatTime,
    formatWhen,
} from '@/lib/format';
import { reservationsPage } from '@/lib/reservations';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { board } from '@/routes/staff';
import { index as requests } from '@/routes/staff/requests';
import type { DashboardAnalytics, DashboardAttention } from '@/types';

// How often the page asks again what needs attention.
const POLL_INTERVAL_MS = 30_000;

// The colours of the two kinds of session, wherever a chart shows both.
const WALK_INS = { label: 'Walk-ins', fill: 'bg-chart-1' };
const RESERVATIONS = { label: 'Reservations', fill: 'bg-chart-2' };

const plural = (count: number, one: string, many: string) =>
    `${count.toLocaleString()} ${count === 1 ? one : many}`;

/**
 * When something happens or happened. Within a week the day is named as it
 * is said ("tomorrow", "on Saturday"); further off, its date is given.
 */
function when(iso: string, now: number): string {
    const days = Math.abs(Date.parse(iso) - now) / 86_400_000;

    return days < 6
        ? formatWhen(iso, new Date(now))
        : `on ${formatShortDate(iso)} at ${formatTime(iso)}`;
}

type AttentionItem = {
    key: string;
    icon: LucideIcon;
    // True for what goes wrong by itself if nobody acts.
    urgent: boolean;
    title: string;
    detail: string;
    action: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

/**
 * Everything that needs dealing with, most pressing first: reservations
 * about to become no-shows, reservations stuck on a closed lane, requests
 * waiting for a call, then lanes that are out of order.
 */
function attentionItems(
    attention: DashboardAttention,
    now: number,
): AttentionItem[] {
    const items: AttentionItem[] = [];

    for (const row of attention.lateReservations) {
        const left = Date.parse(row.noShowAt) - now;

        items.push({
            key: `late-${row.id}`,
            icon: Clock,
            urgent: true,
            title: `${row.customerName} has not checked in`,
            detail: `${formatLanes(row.laneNumbers)} · due at ${formatTime(row.startsAt)} · ${
                left > 0
                    ? `counts as a no-show in ${formatMinutes(left)}`
                    : 'about to count as a no-show'
            }`,
            action: 'Lane board',
            href: board(),
        });
    }

    for (const row of attention.reservationsToMove) {
        items.push({
            key: `move-${row.id}`,
            icon: TriangleAlert,
            urgent: true,
            title: `${row.customerName}'s reservation needs moving`,
            detail: `${formatLanes(row.closedLaneNumbers)} closed · starts ${when(row.startsAt, now)}`,
            action: 'Move lanes',
            href: reservationsPage(toDateInput(new Date(row.startsAt))),
        });
    }

    if (attention.requests.waiting > 0) {
        const { waiting, callNow } = attention.requests;

        items.push({
            key: 'requests',
            icon: Inbox,
            urgent: false,
            title: `${plural(waiting, 'reservation request', 'reservation requests')} waiting`,
            detail:
                callNow > 0
                    ? `${callNow} ${callNow === 1 ? 'is' : 'are'} good to call now.`
                    : 'Call the customer to confirm or decline each one.',
            action: 'Requests',
            href: requests(),
        });
    }

    for (const lane of attention.closedLanes) {
        items.push({
            key: `lane-${lane.number}`,
            icon: Wrench,
            urgent: lane.overdue,
            title: `Lane ${lane.number} is out of order`,
            detail:
                lane.until === null
                    ? 'No date was given for when it will be back.'
                    : lane.overdue
                      ? `It was expected back ${when(lane.until, now)}. Reopen it once it is fixed.`
                      : `Expected back ${when(lane.until, now)}.`,
            action: 'Lane board',
            href: board(),
        });
    }

    return items;
}

function NeedsAttention({
    attention,
    now,
}: {
    attention: DashboardAttention;
    now: number;
}) {
    const items = attentionItems(attention, now);

    return (
        <section className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <h2 className="mb-3 font-semibold">Needs attention</h2>

            {items.length === 0 ? (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <CircleCheck aria-hidden className="size-4 shrink-0" />
                    Nothing needs dealing with right now.
                </p>
            ) : (
                <ul className="divide-y">
                    {items.map((item) => (
                        <li
                            key={item.key}
                            className="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <item.icon
                                aria-hidden
                                className={cn(
                                    'size-5 shrink-0',
                                    item.urgent
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-muted-foreground',
                                )}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{item.title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {item.detail}
                                </p>
                            </div>
                            <Button size="sm" variant="outline" asChild>
                                <Link href={item.href}>{item.action}</Link>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function StatTile({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-3xl font-semibold tracking-tight">
                {value}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">{detail}</p>
        </div>
    );
}

/**
 * Which columns get something written under them: about seven at most, and
 * always the last one.
 */
function labelled(index: number, count: number): boolean {
    return (count - 1 - index) % Math.ceil(count / 7) === 0;
}

function Analytics({
    analytics,
    periods,
}: {
    analytics: DashboardAnalytics;
    periods: number[];
}) {
    const { sessions, waitlist } = analytics;
    const played = sessions.walkIns + sessions.reservations;
    const withinAWeek = analytics.byDay.length <= 7;

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold">How the venue is doing</h2>
                    <p className="text-sm text-muted-foreground">
                        Days and hours are on the venue's own clock.
                    </p>
                </div>

                <nav
                    aria-label="Days covered"
                    className="flex gap-1 rounded-lg border p-1"
                >
                    {periods.map((days) => (
                        <Link
                            key={days}
                            href={dashboard({ query: { days } })}
                            only={['analytics']}
                            preserveScroll
                            preserveState
                            replace
                            aria-current={
                                days === analytics.days ? 'true' : undefined
                            }
                            className="rounded-md px-3 py-1 text-sm font-medium text-muted-foreground hover:bg-muted aria-[current]:bg-primary aria-[current]:text-primary-foreground"
                        >
                            {days === 1 ? 'Today' : `${days} days`}
                        </Link>
                    ))}
                </nav>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <StatTile
                    label="Sessions played"
                    value={played.toLocaleString()}
                    detail={`${plural(sessions.walkIns, 'walk-in', 'walk-ins')} · ${plural(sessions.reservations, 'reservation', 'reservations')}`}
                />
                <StatTile
                    label="People bowling"
                    value={analytics.people.toLocaleString()}
                    detail={
                        played > 0
                            ? `About ${(analytics.people / played).toFixed(1)} to a session`
                            : 'Nobody has played yet'
                    }
                />
                <StatTile
                    label="Lane hours"
                    value={Number(
                        (analytics.laneMinutes / 60).toFixed(1),
                    ).toLocaleString()}
                    detail="Time on the lanes, all sessions together"
                />
                <StatTile
                    label="No-shows"
                    value={analytics.noShows.toLocaleString()}
                    detail={`Of ${plural(sessions.reservations + analytics.noShows, 'reservation', 'reservations')} due · ${analytics.cancelled.toLocaleString()} cancelled`}
                />
                <StatTile
                    label="Groups in the waitlist"
                    value={waitlist.joined.toLocaleString()}
                    detail={`${waitlist.joinedOnline.toLocaleString()} joined online · ${waitlist.missedCalls.toLocaleString()} missed their call · ${waitlist.left.toLocaleString()} left the line`}
                />
                <StatTile
                    label="Average wait"
                    value={
                        waitlist.averageWaitMinutes === null
                            ? '–'
                            : formatMinutes(
                                  waitlist.averageWaitMinutes * 60_000,
                              )
                    }
                    detail="From joining the line to being called"
                />
            </div>

            <div className="grid items-start gap-4 xl:grid-cols-2">
                {analytics.byDay.length > 1 && (
                    <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
                        <h3 className="mb-3 font-medium">Sessions by day</h3>
                        <ColumnChart
                            series={[WALK_INS, RESERVATIONS]}
                            columns={analytics.byDay.map((day, index) => ({
                                key: day.date,
                                label: formatDate(day.date),
                                tick: labelled(index, analytics.byDay.length)
                                    ? formatDateTick(day.date, withinAWeek)
                                    : null,
                                values: [day.walkIns, day.reservations],
                            }))}
                            empty="No sessions were played in these days."
                        />
                    </div>
                )}

                <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
                    <h3 className="mb-3 font-medium">
                        Sessions by the hour they started
                    </h3>
                    <ColumnChart
                        series={[{ label: 'Sessions', fill: 'bg-chart-1' }]}
                        columns={analytics.byHour.map((row, index) => ({
                            key: String(row.hour),
                            label: `${formatHour(row.hour)} to ${formatHour((row.hour + 1) % 24)}`,
                            tick: labelled(index, analytics.byHour.length)
                                ? formatHour(row.hour)
                                : null,
                            values: [row.sessions],
                        }))}
                        empty={
                            analytics.days === 1
                                ? 'No sessions have been played today.'
                                : 'No sessions were played in these days.'
                        }
                    />
                </div>
            </div>
        </section>
    );
}

/**
 * Where staff land after logging in: what needs dealing with now, which
 * refreshes itself, and how the venue has been doing. A user who has not been
 * given a role is shown neither.
 */
export default function Dashboard({
    attention,
    analytics,
    periods,
    serverNow,
}: {
    // Both null for a user with no role.
    attention: DashboardAttention | null;
    analytics: DashboardAnalytics | null;
    // The numbers of days the analytics can cover; 1 is today alone.
    periods: number[];
    serverNow: string;
}) {
    usePoll(POLL_INTERVAL_MS, {
        only: ['attention', 'serverNow', 'requestsWaiting'],
    });

    const now = useServerClock(serverNow);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold tracking-tight">
                    Dashboard
                </h1>

                {attention === null || analytics === null ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CalendarClock
                            aria-hidden
                            className="size-4 shrink-0"
                        />
                        Your account has not been given a role yet, so there is
                        nothing to show here. Ask an admin to set one under
                        Settings, Users.
                    </p>
                ) : (
                    <>
                        <NeedsAttention attention={attention} now={now} />
                        <Analytics analytics={analytics} periods={periods} />
                    </>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
