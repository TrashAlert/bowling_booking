import { Form, Head, usePoll } from '@inertiajs/react';
import { Phone } from 'lucide-react';
import { useState } from 'react';
import BookingRequestController from '@/actions/App/Http/Controllers/Staff/BookingRequestController';
import InputError from '@/components/input-error';
import { ReservationFormDialog } from '@/components/staff/reservation-form-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toDateInput } from '@/lib/dates';
import {
    formatSessionLength,
    formatShortDate,
    formatTime,
    formatTimeOfDay,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/staff/requests';
import type {
    BookingRequestRow,
    LaneOption,
    ReservationLimits,
    SessionRules,
} from '@/types';

// How often the page looks for new requests.
const POLL_INTERVAL_MS = 30_000;

const handledLabels: Record<BookingRequestRow['status'], string> = {
    pending: 'Waiting',
    missed: 'Missed',
    confirmed: 'Confirmed',
    declined: 'Declined',
};

const handledStyles: Record<BookingRequestRow['status'], string> = {
    pending: '',
    missed: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
    confirmed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    declined:
        'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
};

/**
 * When the customer said they can be called.
 */
function callWindow(row: BookingRequestRow): string {
    return row.contactFrom && row.contactUntil
        ? `Call between ${formatTimeOfDay(row.contactFrom)} and ${formatTimeOfDay(row.contactUntil)}`
        : 'Call any time';
}

/**
 * What the customer asked for, in one line.
 */
function asked(row: BookingRequestRow): string {
    return [
        `${formatShortDate(row.startsAt)} at ${formatTime(row.startsAt)}`,
        formatSessionLength(row.minutes),
        `${row.partySize} ${row.partySize === 1 ? 'person' : 'people'}`,
        `${row.lanesNeeded} ${row.lanesNeeded === 1 ? 'lane' : 'lanes'}`,
    ].join(' · ');
}

function RequestDetails({ row }: { row: BookingRequestRow }) {
    return (
        <div className="min-w-0 flex-1 basis-64 space-y-1">
            <p className="flex flex-wrap items-center gap-2 font-medium">
                <span className="truncate">{row.name}</span>
                {row.callNow && (
                    <Badge className="border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300">
                        Good time to call now
                    </Badge>
                )}
                {row.status !== 'pending' && (
                    <Badge
                        className={cn(
                            'border-transparent',
                            handledStyles[row.status],
                        )}
                    >
                        {handledLabels[row.status]}
                    </Badge>
                )}
            </p>
            <p className="text-sm">{asked(row)}</p>
            <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                <a
                    href={`tel:${row.phone}`}
                    className="inline-flex items-center gap-1 font-medium text-foreground tabular-nums underline-offset-4 hover:underline"
                >
                    <Phone aria-hidden className="size-3.5" />
                    {row.phone}
                </a>
                <span>· {callWindow(row)}</span>
            </p>
            {row.notes && (
                <p className="text-sm text-muted-foreground italic">
                    {row.notes}
                </p>
            )}
            {row.status === 'declined' && row.declineReason && (
                <p className="text-sm text-muted-foreground">
                    Declined: {row.declineReason}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                Sent {formatShortDate(row.submittedAt)} at{' '}
                {formatTime(row.submittedAt)}
                {row.handledAt &&
                    ` · ${handledLabels[row.status]} ${formatShortDate(row.handledAt)} at ${formatTime(row.handledAt)}${row.handledBy ? ` by ${row.handledBy}` : ''}`}
            </p>
        </div>
    );
}

function DeclineDialog({
    row,
    onClose,
}: {
    row: BookingRequestRow;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>Decline {row.name}'s request?</DialogTitle>
                <DialogDescription>
                    Let the customer know when you call them. The reason is only
                    kept for staff.
                </DialogDescription>
                <Form
                    {...BookingRequestController.destroy.form(row.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="decline-reason">
                                    Reason (optional)
                                </Label>
                                <Input
                                    id="decline-reason"
                                    name="reason"
                                    maxLength={255}
                                    autoComplete="off"
                                    placeholder="Fully booked that evening"
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={onClose}
                                >
                                    Keep it
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Decline request
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

type OpenDialog =
    | { kind: 'confirm'; row: BookingRequestRow }
    | { kind: 'decline'; row: BookingRequestRow };

/**
 * Reservation requests customers sent from the public form. Staff call the
 * customer, then confirm the request as a reservation or decline it.
 */
export default function Requests({
    waiting,
    handled,
    session,
    limits,
    laneOptions,
}: {
    waiting: BookingRequestRow[];
    handled: BookingRequestRow[];
    session: SessionRules;
    limits: ReservationLimits;
    laneOptions?: LaneOption[];
}) {
    const [tab, setTab] = useState<'waiting' | 'handled'>('waiting');
    const [dialog, setDialog] = useState<OpenDialog | null>(null);

    usePoll(POLL_INTERVAL_MS, {
        only: ['waiting', 'handled', 'requestsWaiting'],
    });

    const rows = tab === 'waiting' ? waiting : handled;

    return (
        <>
            <Head title="Requests" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Reservation requests
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Call the customer, then confirm the reservation or
                        decline it. Requests hold no lanes until confirmed.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {(
                        [
                            ['waiting', 'To deal with', waiting.length],
                            ['handled', 'Dealt with or missed', handled.length],
                        ] as const
                    ).map(([key, label, count]) => (
                        <Button
                            key={key}
                            variant={tab === key ? 'secondary' : 'ghost'}
                            size="sm"
                            aria-pressed={tab === key}
                            onClick={() => setTab(key)}
                        >
                            {label}
                            <span className="text-muted-foreground tabular-nums">
                                {count}
                            </span>
                        </Button>
                    ))}
                </div>

                {rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {tab === 'waiting'
                            ? 'No requests to deal with.'
                            : 'Nothing dealt with yet.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card text-card-foreground shadow-sm">
                        {rows.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-3 p-4"
                            >
                                <RequestDetails row={row} />
                                {row.status === 'pending' && (
                                    <div className="flex shrink-0 gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'decline',
                                                    row,
                                                })
                                            }
                                        >
                                            Decline
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'confirm',
                                                    row,
                                                })
                                            }
                                        >
                                            Confirm
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {dialog?.kind === 'decline' && (
                <DeclineDialog
                    row={dialog.row}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'confirm' && (
                <ReservationFormDialog
                    fromRequest={dialog.row}
                    lookup={(query) => index({ query })}
                    day={toDateInput(new Date(dialog.row.startsAt))}
                    search={null}
                    session={session}
                    limits={limits}
                    laneOptions={laneOptions}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

Requests.layout = {
    breadcrumbs: [
        {
            title: 'Requests',
            href: index(),
        },
    ],
};
