import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CheckInButton } from '@/components/staff/check-in-button';
import { MoveLanesDialog } from '@/components/staff/move-lanes-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatLanes, formatSessionLength, formatTime } from '@/lib/format';
import { reservationsPage } from '@/lib/reservations';
import { board } from '@/routes/staff';
import type { LaneOption, ReservationRow } from '@/types';

function Reservation({
    row,
    now,
    onMove,
}: {
    row: ReservationRow;
    now: number;
    onMove: () => void;
}) {
    // A group can't check in while one of its lanes is closed.
    const needsMoving = row.closedLaneNumbers.length > 0;

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
                {needsMoving && (
                    <p className="text-sm font-medium text-red-600 dark:text-red-400">
                        {formatLanes(row.closedLaneNumbers)} closed, needs
                        moving
                    </p>
                )}
            </div>

            {needsMoving ? (
                <Button size="sm" onClick={onMove}>
                    Move lanes
                </Button>
            ) : row.status === 'confirmed' ? (
                <CheckInButton reservation={row} now={now} />
            ) : (
                <Badge variant="secondary">Checked in</Badge>
            )}
        </li>
    );
}

export function ReservationsPanel({
    reservations,
    laneOptions,
    now,
}: {
    reservations: ReservationRow[];
    laneOptions: LaneOption[] | undefined;
    now: number;
}) {
    const [movingId, setMovingId] = useState<number | null>(null);

    // Looked up afresh each time, so the dialog closes by itself if the
    // reservation drops off the board while it is open.
    const moving = reservations.find((row) => row.id === movingId);

    return (
        <section className="rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <h2 className="mb-3 flex items-baseline justify-between font-semibold">
                Reservations
                <span className="text-sm font-normal text-muted-foreground">
                    Next 24 hours ·{' '}
                    <Link
                        href={reservationsPage()}
                        className="text-foreground underline underline-offset-4"
                    >
                        See all
                    </Link>
                </span>
            </h2>

            {reservations.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No reservations coming up.
                </p>
            ) : (
                <ul className="divide-y">
                    {reservations.map((row) => (
                        <Reservation
                            key={row.id}
                            row={row}
                            now={now}
                            onMove={() => setMovingId(row.id)}
                        />
                    ))}
                </ul>
            )}

            {moving && (
                <MoveLanesDialog
                    reservation={moving}
                    laneOptions={laneOptions}
                    lookup={(query) => board({ query })}
                    onClose={() => setMovingId(null)}
                />
            )}
        </section>
    );
}
