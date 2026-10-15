import { Form, Head, Link, usePoll } from '@inertiajs/react';
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
import { useState } from 'react';
import { BusiestTimes } from '@/components/staff/busiest-times';
import InputError from '@/components/input-error';
import { ColumnChart } from '@/components/staff/column-chart';
import type { ChartColumn } from '@/components/staff/column-chart';
import { LaneClosedTime } from '@/components/staff/lane-closed-time';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerClock } from '@/hooks/use-server-clock';
import { addDays, toDateInput } from '@/lib/dates';
import {
    formatDate,
    formatDateTick,
    formatHour,
    formatLanes,
    formatMinutes,
    formatMonth,
    formatShortCalendarDate,
    formatShortDate,
    formatTime,
    formatWhen,
} from '@/lib/format';
import { reservationsPage } from '@/lib/reservations';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { board } from '@/routes/staff';
import { index as requests } from '@/routes/staff/requests';
import type {
    DashboardAnalytics,
    DashboardAttention,
    DashboardRightNow,
} from '@/types';

// How often the page asks again what the lanes are doing and what needs
// attention.
const POLL_INTERVAL_MS = 10_000;

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

/**
 * Join the parts of a line that apply, the first with a capital letter.
 */
function sentence(parts: (string | false)[]): string {
    const line = parts.filter(Boolean).join(' · ');

    return line.charAt(0).toUpperCase() + line.slice(1);
}

function RightNow({ rightNow }: { rightNow: DashboardRightNow }) {
    const { lanes, line } = rightNow;
    const closed = lanes.outOfOrder + lanes.maintenance + lanes.keptClear;

    return (
        <section className="space-y-3">
            <h2 className="flex items-baseline justify-between font-semibold">
                Right now
                <Link
                    href={board()}
                    className="text-sm font-normal underline underline-offset-4"
                >
                    Lane board
                </Link>
            </h2>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    label="Lanes free"
                    value={lanes.free.toLocaleString()}
                    detail={
                        lanes.total === 0
                            ? 'No lanes have been set up yet'
                            : `Of ${plural(lanes.total, 'lane', 'lanes')}`
                    }
                />
                <StatTile
                    label="In play"
                    value={lanes.inPlay.toLocaleString()}
                    detail={
                        sentence([
                            rightNow.nextSessionEndsAt !== null &&
                                `next one ends at ${formatTime(rightNow.nextSessionEndsAt)}`,
                            lanes.heldForParty > 0 &&
                                `${lanes.heldForParty} more held for a group on its way`,
                        ]) || 'Nobody is playing'
                    }
                />
                <StatTile
                    label="Closed"
                    value={closed.toLocaleString()}
                    detail={
                        sentence([
                            lanes.outOfOrder > 0 &&
                                `${lanes.outOfOrder} out of order`,
                            lanes.maintenance > 0 &&
                                `${lanes.maintenance} for re-oil or maintenance`,
                            lanes.keptClear > 0 &&
                                `${lanes.keptClear} kept clear for a reservation`,
                        ]) || 'Every lane is open'
                    }
                />
                <StatTile
                    label="In the line"
                    value={(line.waiting + line.called).toLocaleString()}
                    detail={`${line.waiting.toLocaleString()} waiting · ${line.called.toLocaleString()} called`}
                />
            </div>
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

// A choice of days above the analytics, chosen when marked as current.
const PERIOD_STYLE =
    'rounded-md px-3 py-1 text-sm font-medium text-muted-foreground hover:bg-muted aria-[current]:bg-primary aria-[current]:text-primary-foreground';

/**
 * The Monday of the week a calendar date ("2026-10-07") falls in.
 */
function mondayOf(date: string): string {
    return addDays(date, -((new Date(`${date}T12:00`).getDay() + 6) % 7));
}

/**
 * The columns of the sessions chart: one a day for up to about two months,
 * then one a week from Monday, then one a month, so the columns stay wide
 * enough to read. A week or month cut by the first or last day holds only
 * its days in the span.
 */
function sessionColumns(byDay: DashboardAnalytics['byDay']): {
    title: string;
    columns: ChartColumn[];
} {
    if (byDay.length <= 62) {
        return {
            title: 'Sessions by day',
            columns: byDay.map((day, index) => ({
                key: day.date,
                label: formatDate(day.date),
                tick: labelled(index, byDay.length)
                    ? formatDateTick(day.date, byDay.length <= 7)
                    : null,
                values: [day.walkIns, day.reservations],
            })),
        };
    }

    const byWeek = byDay.length <= 182;
    const groups = new Map<
        string,
        { first: string; last: string; walkIns: number; reservations: number }
    >();

    for (const day of byDay) {
        const key = byWeek ? mondayOf(day.date) : day.date.slice(0, 7);
        const group = groups.get(key) ?? {
            first: day.date,
            last: day.date,
            walkIns: 0,
            reservations: 0,
        };

        group.last = day.date;
        group.walkIns += day.walkIns;
        group.reservations += day.reservations;
        groups.set(key, group);
    }

    const rows = [...groups.values()];

    return {
        title: byWeek ? 'Sessions by week' : 'Sessions by month',
        columns: rows.map((group, index) => ({
            key: group.first,
            label: byWeek
                ? `${formatDateTick(group.first, false)} to ${formatDateTick(group.last, false)}`
                : formatMonth(group.first),
            tick: labelled(index, rows.length)
                ? byWeek
                    ? formatDateTick(group.first, false)
                    : formatMonth(group.first, true)
                : null,
            values: [group.walkIns, group.reservations],
        })),
    };
}

function Analytics({
    analytics,
    periods,
    customRange,
    maxCustomDays,
}: {
    analytics: DashboardAnalytics;
    periods: number[];
    customRange: { from: string; to: string } | null;
    maxCustomDays: number;
}) {
    const { sessions, waitlist } = analytics;
    const played = sessions.walkIns + sessions.reservations;
    const byPeriod = sessionColumns(analytics.byDay);
    const today = toDateInput(new Date());
    // The form for picking the days stays open while they are in use.
    const [choosing, setChoosing] = useState(customRange !== null);

    // Repairs from before the app began logging them are unknown, which
    // matters while the days shown reach back past that.
    const since = analytics.repairsRecordedSince;
    const repairsNote =
        Date.parse(since) > Date.parse(`${analytics.byDay[0].date}T00:00`)
            ? ` Repairs before ${formatShortDate(since)} were not recorded, so they are not counted.`
            : '';

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold">How the venue is doing</h2>
                    <p className="text-sm text-muted-foreground">
                        {customRange !== null &&
                            `${formatShortCalendarDate(analytics.firstDay)} to ${formatShortCalendarDate(analytics.lastDay)}. `}
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
                            only={['analytics', 'customRange']}
                            preserveScroll
                            preserveState
                            replace
                            onClick={() => setChoosing(false)}
                            aria-current={
                                customRange === null && days === analytics.days
                                    ? 'true'
                                    : undefined
                            }
                            className={PERIOD_STYLE}
                        >
                            {days === 1 ? 'Today' : `${days} days`}
                        </Link>
                    ))}
                    <button
                        type="button"
                        aria-expanded={choosing}
                        aria-current={customRange !== null ? 'true' : undefined}
                        onClick={() => setChoosing((open) => !open)}
                        className={PERIOD_STYLE}
                    >
                        Custom
                    </button>
                </nav>
            </div>

            {choosing && (
                <Form
                    {...dashboard.form()}
                    options={{
                        only: ['analytics', 'customRange'],
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                    }}
                    className="flex flex-wrap items-start gap-3 rounded-xl border bg-card p-4 text-card-foreground shadow-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid w-44 gap-2">
                                <Label htmlFor="dashboard-from">
                                    First day
                                </Label>
                                <Input
                                    id="dashboard-from"
                                    name="from"
                                    type="date"
                                    required
                                    max={today}
                                    defaultValue={analytics.firstDay}
                                />
                                <InputError message={errors.from} />
                            </div>
                            <div className="grid w-44 gap-2">
                                <Label htmlFor="dashboard-to">Last day</Label>
                                <Input
                                    id="dashboard-to"
                                    name="to"
                                    type="date"
                                    required
                                    max={today}
                                    defaultValue={analytics.lastDay}
                                />
                                <InputError message={errors.to} />
                            </div>
                            <div className="grid gap-2">
                                {/* Lines the button up with the fields. */}
                                <span aria-hidden className="text-sm">
                                    &nbsp;
                                </span>
                                <Button type="submit" disabled={processing}>
                                    Show
                                </Button>
                            </div>
                            <p className="basis-full text-sm text-muted-foreground">
                                Up to {maxCustomDays} days, ending today at the
                                latest.
                            </p>
                        </>
                    )}
                </Form>
            )}

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
                        <h3 className="mb-3 font-medium">{byPeriod.title}</h3>
                        <ColumnChart
                            series={[WALK_INS, RESERVATIONS]}
                            columns={byPeriod.columns}
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
                            analytics.days > 1
                                ? 'No sessions were played in these days.'
                                : customRange === null
                                  ? 'No sessions have been played today.'
                                  : 'No sessions were played that day.'
                        }
                    />
                </div>

                {/* One day can't show which days of the week are busy. */}
                {analytics.days > 1 && (
                    <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm xl:col-span-2">
                        <h3 className="font-medium">Busiest times</h3>
                        <p className="mb-3 text-sm text-muted-foreground">
                            {analytics.days <= 7
                                ? 'How many sessions started in each hour, by day of the week.'
                                : 'How many sessions start in each hour on an average day of the week.'}
                        </p>
                        <BusiestTimes
                            hours={analytics.byHour.map((row, index) => ({
                                hour: row.hour,
                                tick: labelled(index, analytics.byHour.length)
                                    ? formatHour(row.hour)
                                    : null,
                            }))}
                            rows={analytics.byWeekday}
                        />
                    </div>
                )}

                {/* Beside the hours chart for today alone, else full width. */}
                <div
                    className={cn(
                        'rounded-xl border bg-card p-4 text-card-foreground shadow-sm',
                        analytics.days > 1 && 'xl:col-span-2',
                    )}
                >
                    <h3 className="font-medium">Time out of order</h3>
                    <p className="mb-3 text-sm text-muted-foreground">
                        How long each lane was closed, up to now.
                        {repairsNote}
                    </p>
                    <LaneClosedTime rows={analytics.closedTime} />
                </div>
            </div>
        </section>
    );
}

/**
 * Where staff land after logging in: what the lanes and the line are doing
 * and what needs dealing with now, both of which refresh themselves, and how
 * the venue has been doing. A user who has not been given a role is shown
 * none of it.
 */
export default function Dashboard({
    rightNow,
    attention,
    analytics,
    periods,
    customRange,
    maxCustomDays,
    serverNow,
}: {
    // All three null for a user with no role.
    rightNow: DashboardRightNow | null;
    attention: DashboardAttention | null;
    analytics: DashboardAnalytics | null;
    // The numbers of days the analytics can cover; 1 is today alone.
    periods: number[];
    // The first and last day picked instead, if any.
    customRange: { from: string; to: string } | null;
    maxCustomDays: number;
    serverNow: string;
}) {
    usePoll(POLL_INTERVAL_MS, {
        only: ['rightNow', 'attention', 'serverNow', 'requestsWaiting'],
    });

    const now = useServerClock(serverNow);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold tracking-tight">
                    Dashboard
                </h1>

                {rightNow === null ||
                attention === null ||
                analytics === null ? (
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
                        <RightNow rightNow={rightNow} />
                        <NeedsAttention attention={attention} now={now} />
                        <Analytics
                            analytics={analytics}
                            periods={periods}
                            customRange={customRange}
                            maxCustomDays={maxCustomDays}
                        />
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
