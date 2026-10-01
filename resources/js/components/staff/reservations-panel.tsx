import { Form } from '@inertiajs/react';
import BookingCheckInController from '@/actions/App/Http/Controllers/Staff/BookingCheckInController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatLanes, formatSessionLength, formatTime } from '@/lib/format';
import type { ReservationRow } from '@/types';

function Reservation({ row }: { row: ReservationRow }) {
    return (
        <li className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium">
                    <span className="tabular-nums">
                        {formatTime(row.startsAt)}
                    </span>{' '}
                    · {row.customerName}
                </p>
                <p className="truncate text-sm text-muted-foreground">
                    {row.partySize} {row.partySize === 1 ? 'person' : 'people'}{' '}
                    · {formatSessionLength(row.minutes)} ·{' '}
                    {formatLanes(row.laneNumbers)}
                </p>
                {row.phone && (
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {row.phone}
                    </p>
                )}
            </div>

            {row.status === 'confirmed' ? (
                <Form
                    {...BookingCheckInController.store.form(row.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button size="sm" disabled={processing}>
                            Check in
                        </Button>
                    )}
                </Form>
            ) : (
                <Badge variant="secondary">Checked in</Badge>
            )}
        </li>
    );
}

export function ReservationsPanel({
    reservations,
}: {
    reservations: ReservationRow[];
}) {
    return (
        <section className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <h2 className="mb-3 flex items-baseline justify-between font-semibold">
                Reservations
                <span className="text-sm font-normal text-muted-foreground">
                    Next 24 hours
                </span>
            </h2>

            {reservations.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No reservations coming up.
                </p>
            ) : (
                <ul className="divide-y">
                    {reservations.map((row) => (
                        <Reservation key={row.id} row={row} />
                    ))}
                </ul>
            )}
        </section>
    );
}
