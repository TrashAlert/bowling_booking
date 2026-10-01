import { Form } from '@inertiajs/react';
import LaneController from '@/actions/App/Http/Controllers/Staff/LaneController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCountdown, formatMinutes, formatTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LaneCard as LaneCardData, LaneCardState } from '@/types';

const stateLabels: Record<LaneCardState, string> = {
    free: 'Free',
    in_play: 'In play',
    held: 'Held',
    reserved: 'Reserved',
    closed_for_reservation: 'Closed',
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
    blocked: {
        accent: 'border-l-neutral-400',
        badge: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
    },
    out_of_order: {
        accent: 'border-l-red-500',
        badge: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
    },
};

function LaneActivity({ lane, now }: { lane: LaneCardData; now: number }) {
    const { current, state } = lane;

    if (!current) {
        return (
            <p className="text-sm text-muted-foreground">
                {state === 'out_of_order'
                    ? 'Closed to new bookings.'
                    : 'Nobody on this lane.'}
            </p>
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

export function LaneCard({ lane, now }: { lane: LaneCardData; now: number }) {
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
                <p className="truncate text-xs text-muted-foreground">
                    {lane.next
                        ? `${lane.next.isClosure ? 'Closes' : 'Next'} ${formatTime(lane.next.startsAt)} · ${lane.next.customerName ?? lane.next.note ?? 'Blocked'}`
                        : 'Nothing booked next'}
                </p>

                <Form
                    {...LaneController.update.form(lane.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="status"
                                value={lane.isOpen ? 'out_of_order' : 'open'}
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={processing}
                                className="h-7 px-2 text-xs"
                            >
                                {lane.isOpen ? 'Close lane' : 'Reopen'}
                            </Button>
                        </>
                    )}
                </Form>
            </footer>
        </article>
    );
}
