import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useServerClock } from '@/hooks/use-server-clock';
import {
    formatCountdown,
    formatLanes,
    formatMoney,
    formatSessionLength,
    formatTime,
} from '@/lib/format';
import { join } from '@/routes/waitlist';
import type { WaitlistTicket } from '@/types';

// How often the page asks where the party is in line.
const POLL_INTERVAL_MS = 5000;

const titles: Record<WaitlistTicket['status'], string> = {
    waiting: 'You are in line',
    called: 'It is your turn',
    seated: 'You are playing',
    skipped: 'You missed your call',
    left: 'You have left the line',
};

/**
 * What a party in the given state is told about its deposit.
 */
function depositNote(ticket: WaitlistTicket, amount: string): string | null {
    switch (ticket.deposit?.outcome) {
        case 'held':
            return `Deposit paid: ${amount}.`;
        case 'applied':
            return `Your ${amount} deposit comes off your bill. Remind our staff at the counter.`;
        case 'refund_due':
            return `Your ${amount} deposit will be returned to you. Please see our staff at the counter.`;
        case 'forfeited':
            return `Your ${amount} deposit is not returned.`;
        default:
            return null;
    }
}

/**
 * What leaving costs a party. Once it has been called a lane is being held
 * for it, so leaving then counts as a missed call.
 */
function leaveWarning(ticket: WaitlistTicket, amount: string | null): string {
    if (ticket.status === 'called') {
        return amount
            ? `A lane is being held for you. If you leave now you lose it, and your ${amount} deposit is not returned.`
            : 'A lane is being held for you. If you leave now you lose it.';
    }

    return amount
        ? `You will lose your place. Your ${amount} deposit will be returned to you.`
        : 'You will lose your place.';
}

function Status({
    ticket,
    now,
    checkInMinutes,
}: {
    ticket: WaitlistTicket;
    now: number;
    checkInMinutes: number;
}) {
    const lanes = formatLanes(ticket.laneNumbers);

    switch (ticket.status) {
        case 'waiting':
            return (
                <>
                    <div className="rounded-lg border p-6 text-center">
                        <p className="text-sm text-muted-foreground">
                            Your place in line
                        </p>
                        <p className="text-6xl font-semibold tabular-nums">
                            {ticket.position}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {ticket.partiesAhead === 0
                                ? 'You are next'
                                : `${ticket.partiesAhead} ${ticket.partiesAhead === 1 ? 'group' : 'groups'} ahead of you`}
                        </p>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Stay close by. When it is your turn this page will say
                        so, and you have {checkInMinutes} minutes to check in at
                        the counter.
                    </p>
                </>
            );
        case 'called':
            return (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-6 text-center dark:border-amber-500/40 dark:bg-amber-500/10">
                    <p className="text-lg font-semibold">
                        Go to the counter now
                    </p>
                    {ticket.laneNumbers.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {lanes} is being held for you
                        </p>
                    )}
                    {ticket.checkInBy && (
                        <>
                            <p className="mt-3 text-6xl font-semibold tabular-nums">
                                {formatCountdown(
                                    Date.parse(ticket.checkInBy) - now,
                                )}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                left to check in
                            </p>
                        </>
                    )}
                </div>
            );
        case 'seated':
            return (
                <div className="rounded-lg border p-6 text-center">
                    {ticket.laneNumbers.length > 0 && (
                        <p className="text-lg font-semibold">{lanes}</p>
                    )}
                    {ticket.sessionEndsAt && (
                        <p className="text-sm text-muted-foreground">
                            {Date.parse(ticket.sessionEndsAt) > now
                                ? `Your session ends at ${formatTime(ticket.sessionEndsAt)}`
                                : 'Your session has ended. Thank you for playing.'}
                        </p>
                    )}
                </div>
            );
        case 'skipped':
            return (
                <p className="text-sm text-muted-foreground">
                    We called you and you did not check in within{' '}
                    {checkInMinutes} minutes, so your lane went to the next
                    group.
                </p>
            );
        case 'left':
            return (
                <p className="text-sm text-muted-foreground">
                    You are no longer in the line.
                </p>
            );
    }
}

/**
 * A party's own page about its place in the waitlist, reached by the secret
 * link it was given. It refreshes itself while the party is waiting, called
 * or playing. Leaving posts back to this page's own address.
 */
export default function WaitlistShow({
    ticket,
    checkInMinutes,
    serverNow,
}: {
    ticket: WaitlistTicket;
    checkInMinutes: number;
    serverNow: string;
}) {
    const { props, url } = usePage();
    const now = useServerClock(serverNow);
    const [leaving, setLeaving] = useState(false);

    const inLine = ticket.status === 'waiting' || ticket.status === 'called';
    const isOver = ticket.status === 'skipped' || ticket.status === 'left';

    const { stop } = usePoll(POLL_INTERVAL_MS, {
        only: ['ticket', 'serverNow'],
    });

    // Nothing more can change once the party is out of the line.
    useEffect(() => {
        if (isOver) {
            stop();
        }
    }, [isOver, stop]);

    const amount = ticket.deposit
        ? formatMoney(ticket.deposit.amountCents, props.currencySymbol)
        : null;
    const note = amount ? depositNote(ticket, amount) : null;

    return (
        <>
            <Head title={titles[ticket.status]} />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">
                        {titles[ticket.status]}, {ticket.customerName}
                    </CardTitle>
                    <CardDescription>
                        {ticket.partySize}{' '}
                        {ticket.partySize === 1 ? 'person' : 'people'} ·{' '}
                        {formatSessionLength(ticket.minutes)}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <Status
                        ticket={ticket}
                        now={now}
                        checkInMinutes={checkInMinutes}
                    />

                    {note && <p className="text-sm font-medium">{note}</p>}

                    {inLine && (
                        <Button
                            variant="outline"
                            className="w-full"
                            onClick={() => setLeaving(true)}
                        >
                            Leave the line
                        </Button>
                    )}

                    {isOver && (
                        <Button variant="outline" className="w-full" asChild>
                            <Link href={join()}>Join the line again</Link>
                        </Button>
                    )}
                </CardContent>
            </Card>

            {inLine && (
                <p className="text-center text-xs text-muted-foreground">
                    Keep this page open or save its address. It is your place in
                    line.
                </p>
            )}

            <Dialog open={leaving && inLine} onOpenChange={setLeaving}>
                <DialogContent>
                    <DialogTitle>Leave the line?</DialogTitle>
                    <DialogDescription>
                        {leaveWarning(ticket, amount)}
                    </DialogDescription>
                    <Form
                        action={url.split('?')[0]}
                        method="delete"
                        onSuccess={() => setLeaving(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setLeaving(false)}
                                >
                                    Stay in line
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Leave the line
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
