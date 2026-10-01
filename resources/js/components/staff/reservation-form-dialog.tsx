import { Form, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ReservationController from '@/actions/App/Http/Controllers/Staff/ReservationController';
import InputError from '@/components/input-error';
import { LanePicker } from '@/components/staff/lane-picker';
import {
    defaultSessionLength,
    SessionLengthPicker,
} from '@/components/staff/session-length-picker';
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
import {
    addDays,
    browserTimeZone,
    localToIso,
    nextStartTime,
    startTimes,
    toDateInput,
    toTimeInput,
} from '@/lib/dates';
import { formatMinutes, formatTimeOfDay } from '@/lib/format';
import { index } from '@/routes/staff/reservations';
import type {
    LaneOption,
    ReservationDetail,
    ReservationLimits,
    SessionRules,
} from '@/types';

/**
 * Makes a reservation, or changes the given one. The lanes on offer are
 * looked up again whenever the date, time or length changes.
 */
export function ReservationFormDialog({
    reservation,
    day,
    search,
    session,
    limits,
    laneOptions,
    onClose,
}: {
    reservation?: ReservationDetail;
    // The day the list is showing, used as the starting date for a new one.
    day: string;
    // What the list is being searched for, so a lane lookup keeps the search.
    search: string | null;
    session: SessionRules;
    limits: ReservationLimits;
    laneOptions: LaneOption[] | undefined;
    onClose: () => void;
}) {
    const [opened] = useState(() => new Date());
    const today = toDateInput(opened);

    const [start, setStart] = useState(() => {
        if (reservation) {
            const startsAt = new Date(reservation.startsAt);

            return {
                date: toDateInput(startsAt),
                time: toTimeInput(startsAt),
            };
        }

        return day > today
            ? { date: day, time: '18:00' }
            : nextStartTime(opened, session.stepMinutes);
    });
    const [minutes, setMinutes] = useState(
        reservation?.minutes ?? defaultSessionLength(session),
    );
    const [partySize, setPartySize] = useState(
        String(reservation?.partySize ?? 2),
    );
    const [selected, setSelected] = useState(
        reservation?.lanes.map((lane) => lane.id) ?? [],
    );
    const [loading, setLoading] = useState(false);

    const startsAt = localToIso(start.date, start.time);
    const lanesKey = `${startsAt}|${minutes}`;
    const loadedKey = useRef<string | null>(null);
    const lanesMissing = laneOptions === undefined;

    useEffect(() => {
        if (!startsAt || (!lanesMissing && loadedKey.current === lanesKey)) {
            return;
        }

        loadedKey.current = lanesKey;

        // The address is built from scratch each time, so nothing left over
        // from an earlier lookup (such as another booking's id) rides along.
        router.visit(
            index({
                query: {
                    date: day,
                    tz: browserTimeZone(),
                    ...(search ? { search } : {}),
                    starts_at: startsAt,
                    minutes,
                    ...(reservation ? { booking: reservation.id } : {}),
                },
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
    }, [startsAt, minutes, lanesKey, lanesMissing, reservation, day, search]);

    const people = Number(partySize);
    const usualLanes = Math.ceil(people / session.maxPlayersPerLane);
    const picked = selected.filter((id) =>
        laneOptions?.some((lane) => lane.id === id && lane.available),
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-xl">
                <DialogTitle>
                    {reservation
                        ? `Change ${reservation.customerName}'s reservation`
                        : 'New reservation'}
                </DialogTitle>
                <DialogDescription>
                    Each lane closes{' '}
                    {formatMinutes(limits.leadMinutes * 60_000)} before the
                    start, so it has to be empty from then on.
                </DialogDescription>

                <Form
                    {...(reservation
                        ? ReservationController.update.form(reservation.id)
                        : ReservationController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-2 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="reservation-name">
                                        Name
                                    </Label>
                                    <Input
                                        id="reservation-name"
                                        name="name"
                                        defaultValue={reservation?.customerName}
                                        required
                                        autoFocus={!reservation}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reservation-phone">
                                        Phone
                                    </Label>
                                    <Input
                                        id="reservation-phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={reservation?.phone ?? ''}
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="reservation-date">
                                        Date
                                    </Label>
                                    <Input
                                        id="reservation-date"
                                        type="date"
                                        value={start.date}
                                        min={today}
                                        max={addDays(
                                            today,
                                            limits.maxDaysAhead,
                                        )}
                                        onChange={(event) =>
                                            setStart({
                                                ...start,
                                                date: event.target.value,
                                            })
                                        }
                                        required
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reservation-time">
                                        Start time
                                    </Label>
                                    <select
                                        id="reservation-time"
                                        value={start.time}
                                        onChange={(event) =>
                                            setStart({
                                                ...start,
                                                time: event.target.value,
                                            })
                                        }
                                        className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                    >
                                        {startTimes(session.stepMinutes).map(
                                            (time) => (
                                                <option key={time} value={time}>
                                                    {formatTimeOfDay(time)}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reservation-party-size">
                                        Party size
                                    </Label>
                                    <Input
                                        id="reservation-party-size"
                                        name="party_size"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={limits.maxPartySize}
                                        value={partySize}
                                        onChange={(event) =>
                                            setPartySize(event.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={errors.party_size} />
                                </div>
                            </div>
                            <input
                                type="hidden"
                                name="starts_at"
                                value={startsAt ?? ''}
                            />
                            <InputError
                                className="-mt-3"
                                message={errors.starts_at}
                            />

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    Length
                                </legend>
                                <SessionLengthPicker
                                    session={session}
                                    value={minutes}
                                    onChange={setMinutes}
                                />
                                <InputError message={errors.minutes} />
                            </fieldset>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 flex w-full items-baseline justify-between text-sm leading-none font-medium">
                                    Lanes
                                    <span className="font-normal text-muted-foreground">
                                        {picked.length} picked
                                        {usualLanes > 1 &&
                                            ` · usually ${usualLanes} for ${people} people`}
                                    </span>
                                </legend>
                                <LanePicker
                                    lanes={laneOptions}
                                    selected={selected}
                                    onChange={setSelected}
                                    loading={loading}
                                />
                                <InputError
                                    message={
                                        errors.lane_ids ?? errors['lane_ids.0']
                                    }
                                />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="reservation-notes">
                                    Notes (optional)
                                </Label>
                                <Input
                                    id="reservation-notes"
                                    name="notes"
                                    defaultValue={reservation?.notes ?? ''}
                                    maxLength={500}
                                    autoComplete="off"
                                />
                                <InputError message={errors.notes} />
                            </div>

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
                                    {reservation
                                        ? 'Save changes'
                                        : 'Make reservation'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
