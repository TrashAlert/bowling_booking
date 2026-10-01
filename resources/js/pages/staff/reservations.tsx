import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CancelReservationDialog } from '@/components/staff/cancel-reservation-dialog';
import { CheckInButton } from '@/components/staff/check-in-button';
import { MoveLanesDialog } from '@/components/staff/move-lanes-dialog';
import { ReservationFormDialog } from '@/components/staff/reservation-form-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useServerClock } from '@/hooks/use-server-clock';
import { addDays, browserTimeZone } from '@/lib/dates';
import {
    formatDate,
    formatLanes,
    formatSessionLength,
    formatTime,
} from '@/lib/format';
import { reservationsPage } from '@/lib/reservations';
import { cn } from '@/lib/utils';
import { index } from '@/routes/staff/reservations';
import type {
    BookingStatus,
    LaneOption,
    ReservationDetail,
    ReservationLimits,
    SessionRules,
} from '@/types';

type Props = {
    date: string;
    reservations: ReservationDetail[];
    session: SessionRules;
    limits: ReservationLimits;
    laneOptions?: LaneOption[];
    serverNow: string;
};

const statusLabels: Record<BookingStatus, string> = {
    pending: 'Not confirmed',
    confirmed: 'Confirmed',
    checked_in: 'Checked in',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'No-show',
};

const statusStyles: Record<BookingStatus, string> = {
    pending:
        'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
    confirmed:
        'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-300',
    checked_in: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled:
        'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
    no_show: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
};

const filters = {
    all: { label: 'All', matches: () => true },
    upcoming: {
        label: 'Still to come',
        matches: (row: ReservationDetail) => row.status === 'confirmed',
    },
    dropped: {
        label: 'Cancelled and no-shows',
        matches: (row: ReservationDetail) =>
            row.status === 'cancelled' || row.status === 'no_show',
    },
};

type Filter = keyof typeof filters;

type OpenDialog =
    | { kind: 'create' }
    | { kind: 'change'; reservation: ReservationDetail }
    | { kind: 'cancel'; reservation: ReservationDetail }
    | { kind: 'move'; reservation: ReservationDetail };

function ReservationRow({
    row,
    now,
    onChange,
    onCancel,
    onMove,
}: {
    row: ReservationDetail;
    now: number;
    onChange: () => void;
    onCancel: () => void;
    onMove: () => void;
}) {
    // A group can't check in while one of its lanes is closed.
    const needsMoving = row.closedLaneNumbers.length > 0;

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
            <p className="w-36 shrink-0 font-medium tabular-nums">
                {formatTime(row.startsAt)} – {formatTime(row.endsAt)}
            </p>

            <div className="min-w-0 flex-1 basis-56">
                <p className="flex items-center gap-2 font-medium">
                    <span className="truncate">{row.customerName}</span>
                    <Badge
                        className={cn(
                            'border-transparent',
                            statusStyles[row.status],
                        )}
                    >
                        {statusLabels[row.status]}
                    </Badge>
                </p>
                <p className="text-sm text-muted-foreground">
                    {row.partySize} {row.partySize === 1 ? 'person' : 'people'}{' '}
                    · {formatSessionLength(row.minutes)} ·{' '}
                    {formatLanes(row.lanes.map((lane) => lane.number))}
                    {row.phone && (
                        <span className="tabular-nums"> · {row.phone}</span>
                    )}
                </p>
                {row.notes && (
                    <p className="text-sm text-muted-foreground italic">
                        {row.notes}
                    </p>
                )}
                {needsMoving && (
                    <p className="text-sm font-medium text-red-600 dark:text-red-400">
                        {formatLanes(row.closedLaneNumbers)} closed, needs
                        moving
                    </p>
                )}
            </div>

            {row.status === 'confirmed' && (
                <div className="flex shrink-0 gap-2">
                    <Button variant="destructive" size="sm" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button variant="outline" size="sm" onClick={onChange}>
                        Change
                    </Button>
                    {needsMoving ? (
                        <Button size="sm" onClick={onMove}>
                            Move lanes
                        </Button>
                    ) : (
                        <CheckInButton reservation={row} now={now} />
                    )}
                </div>
            )}
        </li>
    );
}

export default function Reservations({
    date,
    reservations,
    session,
    limits,
    laneOptions,
    serverNow,
}: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const now = useServerClock(serverNow);

    // The server can't know this device's time zone on the first visit, so it
    // guessed the day in UTC. Ask again with the zone.
    useEffect(() => {
        if (!new URLSearchParams(window.location.search).has('tz')) {
            router.visit(reservationsPage(), { replace: true });
        }
    }, []);

    const shown = reservations.filter(filters[filter].matches);

    return (
        <>
            <Head title="Reservations" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Reservations
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {formatDate(date)}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={reservationsPage(addDays(date, -1))}
                                aria-label="Previous day"
                            >
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <Input
                            type="date"
                            aria-label="Day"
                            value={date}
                            onChange={(event) =>
                                event.target.value &&
                                router.visit(
                                    reservationsPage(event.target.value),
                                )
                            }
                            className="w-auto"
                        />
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={reservationsPage(addDays(date, 1))}
                                aria-label="Next day"
                            >
                                <ChevronRight />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={reservationsPage()}>Today</Link>
                        </Button>
                        <Button onClick={() => setDialog({ kind: 'create' })}>
                            New reservation
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {(Object.keys(filters) as Filter[]).map((key) => (
                        <Button
                            key={key}
                            variant={filter === key ? 'secondary' : 'ghost'}
                            size="sm"
                            aria-pressed={filter === key}
                            onClick={() => setFilter(key)}
                        >
                            {filters[key].label}
                            <span className="text-muted-foreground tabular-nums">
                                {
                                    reservations.filter(filters[key].matches)
                                        .length
                                }
                            </span>
                        </Button>
                    ))}
                </div>

                {shown.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {reservations.length === 0
                            ? 'No reservations on this day.'
                            : 'No reservations match this filter.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card text-card-foreground shadow-sm">
                        {shown.map((row) => (
                            <ReservationRow
                                key={row.id}
                                row={row}
                                now={now}
                                onChange={() =>
                                    setDialog({
                                        kind: 'change',
                                        reservation: row,
                                    })
                                }
                                onCancel={() =>
                                    setDialog({
                                        kind: 'cancel',
                                        reservation: row,
                                    })
                                }
                                onMove={() =>
                                    setDialog({
                                        kind: 'move',
                                        reservation: row,
                                    })
                                }
                            />
                        ))}
                    </ul>
                )}
            </div>

            {dialog?.kind === 'cancel' && (
                <CancelReservationDialog
                    reservation={dialog.reservation}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'move' && (
                <MoveLanesDialog
                    reservation={dialog.reservation}
                    laneOptions={laneOptions}
                    lookup={(query) =>
                        index({
                            query: { date, tz: browserTimeZone(), ...query },
                        })
                    }
                    onClose={() => setDialog(null)}
                />
            )}
            {(dialog?.kind === 'create' || dialog?.kind === 'change') && (
                <ReservationFormDialog
                    reservation={
                        dialog.kind === 'change'
                            ? dialog.reservation
                            : undefined
                    }
                    day={date}
                    session={session}
                    limits={limits}
                    laneOptions={laneOptions}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

Reservations.layout = {
    breadcrumbs: [
        {
            title: 'Reservations',
            href: index(),
        },
    ],
};
