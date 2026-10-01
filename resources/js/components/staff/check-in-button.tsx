import { Form } from '@inertiajs/react';
import BookingCheckInController from '@/actions/App/Http/Controllers/Staff/BookingCheckInController';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/format';
import type { ReservationDetail } from '@/types';

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Checks a reservation in. Until check-in opens it says when that is
 * instead, since the server refuses a check-in before then.
 */
export function CheckInButton({
    reservation,
    now,
}: {
    reservation: Pick<ReservationDetail, 'id' | 'checkInOpensAt'>;
    now: number;
}) {
    const opensAt = Date.parse(reservation.checkInOpensAt);

    if (now < opensAt) {
        // A clock time alone only says enough for the day ahead.
        const [first, second] =
            opensAt - now < DAY_MS
                ? ['Check-in opens', formatTime(reservation.checkInOpensAt)]
                : ['Too early', 'to check in'];

        return (
            <p className="shrink-0 self-center text-right text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                {first}
                <br />
                {second}
            </p>
        );
    }

    return (
        <Form
            {...BookingCheckInController.store.form(reservation.id)}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button size="sm" disabled={processing}>
                    Check in
                </Button>
            )}
        </Form>
    );
}
