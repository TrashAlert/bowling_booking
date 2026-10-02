import { Form, usePage } from '@inertiajs/react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatCountdown,
    formatLanes,
    formatMinutes,
    formatMoney,
    formatSessionLength,
} from '@/lib/format';
import type { WaitlistRow } from '@/types';

function WaitlistEntry({ row, now }: { row: WaitlistRow; now: number }) {
    const { currencySymbol } = usePage().props;
    const lanes =
        row.laneNumbers.length > 0 ? formatLanes(row.laneNumbers) : null;

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex items-start gap-3">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold tabular-nums">
                    {row.position}
                </span>

                <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{row.customerName}</p>
                    <p className="text-sm text-muted-foreground">
                        {row.partySize}{' '}
                        {row.partySize === 1 ? 'person' : 'people'} ·{' '}
                        {formatSessionLength(row.minutes)}
                    </p>
                    {row.depositCents !== null && (
                        <p className="text-sm text-muted-foreground">
                            Online ·{' '}
                            {formatMoney(row.depositCents, currencySymbol)}{' '}
                            deposit paid
                        </p>
                    )}
                </div>

                {row.status === 'called' ? (
                    <Badge className="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                        Called
                    </Badge>
                ) : (
                    <Badge variant="secondary">Waiting</Badge>
                )}
            </div>

            <div className="flex items-center justify-between gap-2 pl-10">
                {row.status === 'called' && row.checkInBy ? (
                    <p className="text-sm text-muted-foreground">
                        {lanes && `${lanes} · `}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatCountdown(Date.parse(row.checkInBy) - now)}
                        </span>{' '}
                        to check in
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Waiting {formatMinutes(now - Date.parse(row.joinedAt))}
                    </p>
                )}

                <div className="flex shrink-0 gap-2">
                    {row.status === 'called' ? (
                        <>
                            <Form
                                {...WaitlistController.skip.form(row.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Skip
                                    </Button>
                                )}
                            </Form>
                            <Form
                                {...WaitlistController.seat.form(row.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button size="sm" disabled={processing}>
                                        Seat
                                    </Button>
                                )}
                            </Form>
                        </>
                    ) : (
                        <Form
                            {...WaitlistController.destroy.form(row.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Remove
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </li>
    );
}

export function WaitlistPanel({
    waitlist,
    now,
}: {
    waitlist: WaitlistRow[];
    now: number;
}) {
    return (
        <section className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <h2 className="mb-3 flex items-baseline justify-between font-semibold">
                Waitlist
                <span className="text-sm font-normal text-muted-foreground">
                    {waitlist.length} in line
                </span>
            </h2>

            {waitlist.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nobody is waiting.
                </p>
            ) : (
                <ol className="divide-y">
                    {waitlist.map((row) => (
                        <WaitlistEntry key={row.id} row={row} now={now} />
                    ))}
                </ol>
            )}
        </section>
    );
}
