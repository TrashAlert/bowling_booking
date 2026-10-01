import { Form, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ReservationLaneController from '@/actions/App/Http/Controllers/Staff/ReservationLaneController';
import InputError from '@/components/input-error';
import { LanePicker } from '@/components/staff/lane-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatLanes, formatTime } from '@/lib/format';
import type { LaneOption, MovableReservation } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Puts a reservation on other lanes without changing its time. Staff need
 * this when one of its lanes has been closed: the group can't check in until
 * it is off that lane.
 *
 * The page it is on supplies the lanes through its laneOptions prop; lookup
 * builds that page's address for asking which lanes are free.
 */
export function MoveLanesDialog({
    reservation,
    laneOptions,
    lookup,
    onClose,
}: {
    reservation: MovableReservation;
    laneOptions: LaneOption[] | undefined;
    lookup: (query: {
        starts_at: string;
        minutes: number;
        booking: number;
    }) => RouteDefinition<'get'>;
    onClose: () => void;
}) {
    const closed = reservation.closedLaneNumbers;

    // The lanes it can keep start ticked; the closed ones are dropped.
    const [selected, setSelected] = useState(() =>
        reservation.lanes
            .filter((lane) => !closed.includes(lane.number))
            .map((lane) => lane.id),
    );
    const [loading, setLoading] = useState(false);

    const lanesMissing = laneOptions === undefined;

    // The lanes are asked for once when the dialog opens, and again only if
    // the page loses them (a refused move reloads the page without them).
    // This effect re-runs on every render, so the two flags are what stop it
    // from asking again while an answer is on its way.
    const lookedUp = useRef({ asked: false, hadLanes: false });

    useEffect(() => {
        if (!lanesMissing) {
            lookedUp.current.hadLanes = true;
        }

        const lostLanes = lanesMissing && lookedUp.current.hadLanes;

        if (lookedUp.current.asked && !lostLanes) {
            return;
        }

        lookedUp.current = { asked: true, hadLanes: false };

        router.visit(
            lookup({
                starts_at: reservation.startsAt,
                minutes: Math.round(
                    (Date.parse(reservation.endsAt) -
                        Date.parse(reservation.startsAt)) /
                        60_000,
                ),
                booking: reservation.id,
            }),
            {
                only: ['laneOptions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }, [lanesMissing, lookup, reservation]);

    const picked = selected.filter((id) =>
        laneOptions?.some((lane) => lane.id === id && lane.available),
    );
    const nothingFree =
        laneOptions !== undefined &&
        !laneOptions.some((lane) => lane.available);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <DialogTitle>
                    Move {reservation.customerName}'s reservation
                </DialogTitle>
                <DialogDescription>
                    {closed.length > 0
                        ? `${formatLanes(closed)} ${closed.length === 1 ? 'is' : 'are'} closed, so the group can't check in there. `
                        : ''}
                    Tick the lanes for {formatTime(reservation.startsAt)} to{' '}
                    {formatTime(reservation.endsAt)}. The time stays the same.
                </DialogDescription>

                <Form
                    {...ReservationLaneController.update.form(reservation.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 flex w-full items-baseline justify-between text-sm leading-none font-medium">
                                    Lanes
                                    <span className="font-normal text-muted-foreground">
                                        {picked.length} picked
                                    </span>
                                </legend>
                                <LanePicker
                                    lanes={laneOptions}
                                    selected={selected}
                                    onChange={setSelected}
                                    loading={loading}
                                />
                                {nothingFree && (
                                    <p className="text-sm text-muted-foreground">
                                        No lane is free for that time. Wait for
                                        one, reopen the closed lane, or cancel
                                        the reservation.
                                    </p>
                                )}
                                <InputError
                                    message={
                                        errors.lane_ids ?? errors['lane_ids.0']
                                    }
                                />
                            </fieldset>

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={onClose}
                                >
                                    Close
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || picked.length === 0}
                                >
                                    Move lanes
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
