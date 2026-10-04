import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import ReserveLaneController from '@/actions/App/Http/Controllers/ReserveLaneController';
import InputError from '@/components/input-error';
import PhoneInput from '@/components/phone-input';
import {
    defaultSessionLength,
    SessionLengthPicker,
} from '@/components/staff/session-length-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addDays,
    localToIso,
    nextStartTime,
    startTimes,
    toDateInput,
} from '@/lib/dates';
import { formatTimeOfDay } from '@/lib/format';
import { fitsOpeningHours, hoursOn } from '@/lib/opening-hours';
import type { DayHours, SessionRules } from '@/types';

type Limits = {
    maxPartySize: number;
    maxDaysAhead: number;
};

/**
 * The form a customer fills in to ask for a reservation. Nothing is booked:
 * staff call the customer at a time they give, and confirm it then.
 */
function RequestForm({
    session,
    limits,
    openingHours,
}: {
    session: SessionRules;
    limits: Limits;
    openingHours: DayHours[] | null;
}) {
    const [opened] = useState(() => new Date());
    const today = toDateInput(opened);

    const [start, setStart] = useState(() =>
        nextStartTime(opened, session.stepMinutes),
    );
    const [partySize, setPartySize] = useState('2');
    const [minutes, setMinutes] = useState(() => defaultSessionLength(session));
    const [anyTime, setAnyTime] = useState(false);

    const startsAt = localToIso(start.date, start.time);
    const isPast = startsAt !== null && Date.parse(startsAt) < opened.getTime();
    // Whether a session from this time would be wholly within opening hours.
    const fits = (time: string) =>
        openingHours === null ||
        fitsOpeningHours(openingHours, start.date, time, minutes);
    const isClosed = !fits(start.time);
    // Whether any start time on the chosen day works for this length.
    const dayHasTimes = startTimes(session.stepMinutes).some(fits);
    const lanes = Math.max(
        1,
        Math.ceil(Number(partySize) / session.maxPlayersPerLane),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl">Reserve a lane</CardTitle>
                <CardDescription>
                    Tell us when you would like to come. We will call you to
                    confirm, and your lane is only kept once we have.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...ReserveLaneController.store.form()}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-2 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="reserve-name">Name</Label>
                                    <Input
                                        id="reserve-name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reserve-phone">Phone</Label>
                                    <PhoneInput
                                        id="reserve-phone"
                                        required
                                        autoComplete="tel"
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>

                            {/* One above the other: side by side, a phone cuts the year off. */}
                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="reserve-date">Date</Label>
                                    <Input
                                        id="reserve-date"
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
                                    <Label htmlFor="reserve-time">
                                        Start time
                                    </Label>
                                    <select
                                        id="reserve-time"
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
                                                <option
                                                    key={time}
                                                    value={time}
                                                    disabled={!fits(time)}
                                                >
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
                            {openingHours !== null && (
                                <p className="-mt-3 text-xs text-muted-foreground">
                                    {hoursOn(openingHours, start.date)} on this
                                    day.
                                </p>
                            )}
                            {!isPast && isClosed && (
                                <p className="-mt-3 text-sm text-destructive-foreground">
                                    {dayHasTimes
                                        ? 'We are not open for the whole of that session. Pick another time or a shorter session.'
                                        : 'There is no time on this day for a session that long. Pick another day.'}
                                </p>
                            )}
                            {isPast && (
                                <p className="-mt-3 text-sm text-destructive-foreground">
                                    That time has already passed. Pick a later
                                    one.
                                </p>
                            )}
                            <InputError
                                className="-mt-3"
                                message={errors.starts_at}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="reserve-party-size">
                                    How many people
                                </Label>
                                <Input
                                    id="reserve-party-size"
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
                                <p className="text-xs text-muted-foreground">
                                    Up to {session.maxPlayersPerLane} people
                                    share a lane, so your group needs {lanes}{' '}
                                    {lanes === 1 ? 'lane' : 'lanes'}.
                                </p>
                                <InputError message={errors.party_size} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    How long do you want to play?
                                </legend>
                                <SessionLengthPicker
                                    session={session}
                                    value={minutes}
                                    onChange={setMinutes}
                                />
                                <InputError message={errors.minutes} />
                            </fieldset>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    When can we call you to confirm?
                                </legend>
                                <input
                                    type="hidden"
                                    name="call_any_time"
                                    value={anyTime ? '1' : '0'}
                                />
                                {!anyTime && (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="time"
                                            name="contact_from"
                                            aria-label="Call me from"
                                            required
                                            className="w-32"
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            to
                                        </span>
                                        <Input
                                            type="time"
                                            name="contact_until"
                                            aria-label="Call me until"
                                            required
                                            className="w-32"
                                        />
                                    </div>
                                )}
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="reserve-any-time"
                                        checked={anyTime}
                                        onCheckedChange={(checked) =>
                                            setAnyTime(checked === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="reserve-any-time"
                                        className="font-normal"
                                    >
                                        Any time is fine
                                    </Label>
                                </div>
                                <InputError
                                    message={
                                        errors.contact_from ??
                                        errors.contact_until
                                    }
                                />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="reserve-notes">
                                    Anything we should know? (optional)
                                </Label>
                                <Input
                                    id="reserve-notes"
                                    name="notes"
                                    maxLength={500}
                                    autoComplete="off"
                                    placeholder="A birthday, bumpers for children…"
                                />
                                <InputError message={errors.notes} />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing || isPast || isClosed}
                            >
                                Send my request
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

export default function ReserveLane({
    session,
    limits,
    openingHours,
}: {
    session: SessionRules;
    limits: Limits;
    // Reservations must fall within these; null while none are set.
    openingHours: DayHours[] | null;
}) {
    return (
        <>
            <Head title="Reserve a lane" />

            <RequestForm
                session={session}
                limits={limits}
                openingHours={openingHours}
            />
        </>
    );
}
