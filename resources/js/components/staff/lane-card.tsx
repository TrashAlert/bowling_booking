import { router } from '@inertiajs/react';
import { Ellipsis } from 'lucide-react';
import { useState } from 'react';
import LaneClosureController from '@/actions/App/Http/Controllers/Staff/LaneClosureController';
import { AddClosureTimeDialog } from '@/components/staff/add-closure-time-dialog';
import { CloseLaneDialog } from '@/components/staff/close-lane-dialog';
import { EndSessionDialog } from '@/components/staff/end-session-dialog';
import { ExtendSessionDialog } from '@/components/staff/extend-session-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { closureLabels } from '@/lib/closures';
import {
    formatCountdown,
    formatDay,
    formatMinutes,
    formatTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    ClosureOptions,
    LaneCard as LaneCardData,
    LaneCardState,
    SessionRules,
} from '@/types';

const stateLabels: Record<LaneCardState, string> = {
    free: 'Free',
    in_play: 'In play',
    held: 'Held',
    reserved: 'Reserved',
    closed_for_reservation: 'Closed',
    maintenance: 'Closed',
    blocked: 'Blocked',
    out_of_order: 'Out of order',
};

const stateStyles: Record<LaneCardState, { accent: string; badge: string }> = {
    free: {
        accent: 'border-l-emerald-500',
        badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    },
    in_play: {
        accent: 'border-l-sky-500',
        badge: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300',
    },
    held: {
        accent: 'border-l-amber-500',
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
    },
    reserved: {
        accent: 'border-l-violet-500',
        badge: 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-300',
    },
    closed_for_reservation: {
        accent: 'border-l-fuchsia-500',
        badge: 'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
    },
    maintenance: {
        accent: 'border-l-orange-500',
        badge: 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-300',
    },
    blocked: {
        accent: 'border-l-neutral-400',
        badge: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
    },
    out_of_order: {
        accent: 'border-l-red-500',
        badge: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
    },
};

/**
 * Why an out-of-order lane is closed and when it is expected back. The date
 * is only an estimate, so once it has passed it reads as overdue.
 */
function RepairNote({ lane, now }: { lane: LaneCardData; now: number }) {
    const closure = lane.closure;

    if (!closure?.reason) {
        return (
            <p className="text-sm text-muted-foreground">
                Closed to new bookings.
            </p>
        );
    }

    return (
        <div className="space-y-0.5">
            <p className="font-medium">{closureLabels[closure.reason]}</p>
            <p className="text-sm text-muted-foreground">
                {closure.until === null
                    ? 'Until reopened'
                    : Date.parse(closure.until) > now
                      ? `Expected back ${formatDay(closure.until)}`
                      : `Was expected back ${formatDay(closure.until)}`}
            </p>
        </div>
    );
}

function LaneActivity({ lane, now }: { lane: LaneCardData; now: number }) {
    const { current, state } = lane;

    if (!current) {
        return state === 'out_of_order' ? (
            <RepairNote lane={lane} now={now} />
        ) : (
            <p className="text-sm text-muted-foreground">
                Nobody on this lane.
            </p>
        );
    }

    if (state === 'maintenance' && current.closureReason) {
        return (
            <div className="space-y-0.5">
                <p className="font-medium">
                    {closureLabels[current.closureReason]}
                </p>
                <p className="text-sm text-muted-foreground">
                    Reopens in{' '}
                    <span className="font-medium text-foreground tabular-nums">
                        {formatMinutes(Date.parse(current.endsAt) - now)}
                    </span>
                    , at {formatTime(current.endsAt)}
                </p>
            </div>
        );
    }

    const who = current.customerName ?? current.note ?? 'No details';
    const party = current.partySize ? ` · ${current.partySize} people` : '';

    return (
        <div className="space-y-0.5">
            <p className="truncate font-medium">
                {who}
                <span className="font-normal text-muted-foreground">
                    {party}
                </span>
            </p>

            {state === 'held' && current.heldUntil ? (
                <p className="text-sm text-muted-foreground">
                    Check-in closes in{' '}
                    <span className="font-medium text-foreground tabular-nums">
                        {formatCountdown(Date.parse(current.heldUntil) - now)}
                    </span>
                </p>
            ) : state === 'closed_for_reservation' ? (
                <p className="text-sm text-muted-foreground">
                    Reservation at{' '}
                    <span className="font-medium text-foreground tabular-nums">
                        {formatTime(current.endsAt)}
                    </span>
                </p>
            ) : state === 'reserved' ? (
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        Not checked in
                    </span>{' '}
                    · until {formatTime(current.endsAt)}
                </p>
            ) : (
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground tabular-nums">
                        {formatMinutes(Date.parse(current.endsAt) - now)}
                    </span>{' '}
                    left, until {formatTime(current.endsAt)}
                </p>
            )}
        </div>
    );
}

/**
 * What comes next on the lane, in a few words for the card's footer.
 */
function nextUp(lane: LaneCardData): string {
    const next = lane.next;

    if (!next) {
        return 'Nothing booked next';
    }

    const time = formatTime(next.startsAt);

    if (next.closureReason) {
        return `Closes ${time} · ${closureLabels[next.closureReason]}`;
    }

    return `${next.isClosure ? 'Closes' : 'Next'} ${time} · ${next.customerName ?? next.note ?? 'Blocked'}`;
}

export function LaneCard({
    lane,
    now,
    session,
    closureOptions,
}: {
    lane: LaneCardData;
    now: number;
    session: SessionRules;
    closureOptions: ClosureOptions;
}) {
    const [dialog, setDialog] = useState<
        'extend' | 'end' | 'close' | 'addTime' | null
    >(null);

    // Only a party that is playing right now can be given more time or sent
    // off early. The dialogs go when the session does.
    const isRunning =
        lane.current?.isRunning === true &&
        Date.parse(lane.current.endsAt) > now;

    // A short closure that is under way, or waiting for the lane to be free.
    const closedForWork = lane.state === 'maintenance';
    const closureWaiting = lane.next?.closureReason != null;

    const reopen = () =>
        router.delete(LaneClosureController.destroy.url(lane.id), {
            preserveScroll: true,
        });

    return (
        <article
            className={cn(
                'flex flex-col gap-2 rounded-xl border border-l-4 bg-card p-3 text-card-foreground shadow-sm',
                stateStyles[lane.state].accent,
            )}
        >
            <header className="flex items-center justify-between gap-2">
                <h3 className="flex items-baseline gap-1.5">
                    <span className="text-xs font-medium text-muted-foreground uppercase">
                        Lane
                    </span>
                    <span className="text-2xl leading-none font-semibold tabular-nums">
                        {lane.number}
                    </span>
                </h3>
                <div className="flex items-center gap-1.5">
                    {lane.hasBumpers && (
                        <Badge variant="outline">Bumpers</Badge>
                    )}
                    <Badge
                        className={cn(
                            'border-transparent',
                            stateStyles[lane.state].badge,
                        )}
                    >
                        {stateLabels[lane.state]}
                    </Badge>
                </div>
            </header>

            <div className="min-h-11 flex-1">
                <LaneActivity lane={lane} now={now} />
            </div>

            <footer className="flex items-center justify-between gap-2 border-t pt-1.5">
                <p className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                    {nextUp(lane)}
                </p>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Lane ${lane.number} actions`}
                            className="size-7 shrink-0"
                        >
                            <Ellipsis />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {isRunning && (
                            <>
                                <DropdownMenuItem
                                    onSelect={() => setDialog('extend')}
                                >
                                    Extend session
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => setDialog('end')}
                                >
                                    End session
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                            </>
                        )}
                        {closedForWork && (
                            <DropdownMenuItem
                                onSelect={() => setDialog('addTime')}
                            >
                                Add time
                            </DropdownMenuItem>
                        )}
                        {!lane.isOpen || closedForWork ? (
                            <DropdownMenuItem onSelect={reopen}>
                                Reopen lane
                            </DropdownMenuItem>
                        ) : closureWaiting ? (
                            <DropdownMenuItem onSelect={reopen}>
                                Cancel closure
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setDialog('close')}
                            >
                                Close lane
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </footer>

            {isRunning && dialog === 'extend' && (
                <ExtendSessionDialog
                    lane={lane}
                    session={session}
                    onClose={() => setDialog(null)}
                />
            )}
            {isRunning && dialog === 'end' && (
                <EndSessionDialog lane={lane} onClose={() => setDialog(null)} />
            )}
            {dialog === 'close' && (
                <CloseLaneDialog
                    lane={lane}
                    options={closureOptions}
                    onClose={() => setDialog(null)}
                />
            )}
            {closedForWork && dialog === 'addTime' && (
                <AddClosureTimeDialog
                    lane={lane}
                    options={closureOptions}
                    onClose={() => setDialog(null)}
                />
            )}
        </article>
    );
}
