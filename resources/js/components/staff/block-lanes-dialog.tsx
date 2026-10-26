import { Form, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import LaneBlockController from '@/actions/App/Http/Controllers/Staff/LaneBlockController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addDays,
    browserTimeZone,
    localToIso,
    nextStartTime,
    startTimes,
    toDateInput,
} from '@/lib/dates';
import { formatMinutes, formatTimeOfDay } from '@/lib/format';
import { index } from '@/routes/staff/reservations';
import type { LaneOption, ReservationLimits, SessionRules } from '@/types';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

/**
 * Minutes from one clock time to another ("19:00" to "22:00"). An end at or
 * before the start is on the next day.
 */
function minutesBetween(from: string, until: string): number {
    const toMinutes = (time: string) => {
        const [hours, minutes] = time.split(':').map(Number);

        return hours * 60 + minutes;
    };
    const minutes = toMinutes(until) - toMinutes(from);

    return minutes > 0 ? minutes : minutes + 24 * 60;
}

/**
 * Blocks lanes for a league night or an event, once or every week for a
 * while. The lanes on offer are looked up again whenever the date or times
 * change; they are checked for the first week, and the server checks every
 * week when the form is sent.
 */
export function BlockLanesDialog({
    day,
    session,
    limits,
    maxWeeks,
    laneOptions,
    onClose,
}: {
    // The day the Reservations page is showing, where a new block starts.
    day: string;
    session: SessionRules;
    limits: ReservationLimits;
    maxWeeks: number;
    laneOptions: LaneOption[] | undefined;
    onClose: () => void;
}) {
    const [opened] = useState(() => new Date());
    const today = toDateInput(opened);

    const [start, setStart] = useState(() =>
        day > today
            ? { date: day, time: '19:00' }
            : nextStartTime(opened, session.stepMinutes),
    );
    const [until, setUntil] = useState(() => {
        const [hours, minutes] = start.time.split(':').map(Number);

        return `${String((hours + 3) % 24).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    });
    const [weeks, setWeeks] = useState('1');
    const [selected, setSelected] = useState<number[]>([]);
    const [loading, setLoading] = useState(false);

    const startsAt = localToIso(start.date, start.time);
    const minutes = minutesBetween(start.time, until);
    const endsNextDay = until <= start.time;
    const lanesKey = `${startsAt}|${minutes}`;
    const loadedKey = useRef<string | null>(null);
    const lanesMissing = laneOptions === undefined;

    useEffect(() => {
        if (!startsAt || (!lanesMissing && loadedKey.current === lanesKey)) {
            return;
        }

        loadedKey.current = lanesKey;

        router.visit(
            index({
                query: {
                    date: day,
                    tz: browserTimeZone(),
                    starts_at: startsAt,
                    minutes,
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
    }, [startsAt, minutes, lanesKey, lanesMissing, day]);

    const free = laneOptions?.filter((lane) => lane.available) ?? [];
    const picked = selected.filter((id) => free.some((lane) => lane.id === id));

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-xl">
                <DialogTitle>Block lanes</DialogTitle>
                <DialogDescription>
                    For a league night, a private event or a school trip. Nobody
                    is called to a blocked lane and nothing else can be booked
                    on it.
                    {limits.leadMinutes > 0 &&
                        ` Like a reservation, each lane closes ${formatMinutes(limits.leadMinutes * 60_000)} before the start.`}
                </DialogDescription>

                <Form
                    {...LaneBlockController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="block-note">What for</Label>
                                <Input
                                    id="block-note"
                                    name="note"
                                    required
                                    autoFocus
                                    maxLength={100}
                                    autoComplete="off"
                                    placeholder="League night"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <div className="grid grid-cols-3 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="block-date">Date</Label>
                                    <Input
                                        id="block-date"
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
                                    <Label htmlFor="block-from">From</Label>
                                    <select
                                        id="block-from"
                                        value={start.time}
                                        onChange={(event) =>
                                            setStart({
                                                ...start,
                                                time: event.target.value,
                                            })
                                        }
                                        className={selectClass}
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
                                    <Label htmlFor="block-until">Until</Label>
                                    <select
                                        id="block-until"
                                        value={until}
                                        onChange={(event) =>
                                            setUntil(event.target.value)
                                        }
                                        className={selectClass}
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
                            </div>
                            <input
                                type="hidden"
                                name="starts_at"
                                value={startsAt ?? ''}
                            />
                            <input
                                type="hidden"
                                name="minutes"
                                value={minutes}
                            />
                            <p className="-mt-3 text-xs text-muted-foreground">
                                Lasts {formatMinutes(minutes * 60_000)}
                                {endsNextDay && ', ending the next day'}.
                            </p>
                            <InputError
                                className="-mt-3"
                                message={errors.starts_at ?? errors.minutes}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="block-weeks">
                                    Weeks in a row
                                </Label>
                                <Input
                                    id="block-weeks"
                                    name="weeks"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={maxWeeks}
                                    value={weeks}
                                    onChange={(event) =>
                                        setWeeks(event.target.value)
                                    }
                                    required
                                    className="w-24"
                                />
                                <p className="text-xs text-muted-foreground">
                                    1 for just this once. More blocks the same
                                    lanes on the same day of the week, at the
                                    same time.
                                </p>
                                <InputError message={errors.weeks} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 flex w-full items-baseline justify-between text-sm leading-none font-medium">
                                    Lanes
                                    <span className="flex items-baseline gap-3 font-normal text-muted-foreground">
                                        {picked.length} picked
                                        {free.length > 0 && (
                                            <button
                                                type="button"
                                                className="text-foreground underline underline-offset-4"
                                                onClick={() =>
                                                    setSelected(
                                                        free.map(
                                                            (lane) => lane.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Pick every free lane
                                            </button>
                                        )}
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
                                    Block lanes
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
