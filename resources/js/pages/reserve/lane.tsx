import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { SessionLengthPicker } from '@/components/staff/session-length-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addDays,
    localToIso,
    nextStartTime,
    startTimes,
    toDateInput,
} from '@/lib/dates';
import {
    formatDate,
    formatSessionLength,
    formatTime,
    formatTimeOfDay,
} from '@/lib/format';
import { home } from '@/routes';
import type { SessionRules } from '@/types';

type Limits = {
    maxPartySize: number;
    maxDaysAhead: number;
    // How long before the start a reservation can be checked in.
    checkInOpensMinutes: number;
    // How long after the start it is still kept before it is a no-show.
    noShowGraceMinutes: number;
};

// What the customer asked for, kept only on this screen.
type Reservation = {
    name: string;
    date: string;
    startsAt: string;
    minutes: number;
    partySize: number;
    lanes: number;
};

function ReserveForm({
    session,
    limits,
    onReserve,
}: {
    session: SessionRules;
    limits: Limits;
    onReserve: (reservation: Reservation) => void;
}) {
    const [opened] = useState(() => new Date());
    const today = toDateInput(opened);

    const [start, setStart] = useState(() =>
        nextStartTime(opened, session.stepMinutes),
    );
    const [partySize, setPartySize] = useState('2');

    const startsAt = localToIso(start.date, start.time);
    const isPast = startsAt !== null && Date.parse(startsAt) < opened.getTime();
    const lanes = Math.max(
        1,
        Math.ceil(Number(partySize) / session.maxPlayersPerLane),
    );

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (startsAt === null || isPast) {
            return;
        }

        const form = new FormData(event.currentTarget);
        const name = form.get('name');

        onReserve({
            name: typeof name === 'string' ? name.trim() : '',
            date: start.date,
            startsAt,
            minutes: Number(form.get('minutes')),
            partySize: Number(partySize),
            lanes,
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl">Reserve a lane</CardTitle>
                <CardDescription>
                    Pick a date and time and we will keep a lane for your group.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5">
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
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="reserve-phone">Phone</Label>
                            <Input
                                id="reserve-phone"
                                name="phone"
                                type="tel"
                                required
                                maxLength={30}
                                autoComplete="tel"
                            />
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
                                max={addDays(today, limits.maxDaysAhead)}
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
                            <Label htmlFor="reserve-time">Start time</Label>
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
                                {startTimes(session.stepMinutes).map((time) => (
                                    <option key={time} value={time}>
                                        {formatTimeOfDay(time)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {isPast && (
                        <p className="-mt-3 text-sm text-destructive-foreground">
                            That time has already passed. Pick a later one.
                        </p>
                    )}

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
                            Up to {session.maxPlayersPerLane} people share a
                            lane, so your group needs {lanes}{' '}
                            {lanes === 1 ? 'lane' : 'lanes'}.
                        </p>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm leading-none font-medium">
                            How long do you want to play?
                        </legend>
                        <SessionLengthPicker session={session} />
                    </fieldset>

                    <Button type="submit" className="w-full" disabled={isPast}>
                        Make a reservation
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function Reserved({
    reservation,
    limits,
    onAgain,
}: {
    reservation: Reservation;
    limits: Limits;
    onAgain: () => void;
}) {
    const starts = Date.parse(reservation.startsAt);
    const endsAt = new Date(
        starts + reservation.minutes * 60_000,
    ).toISOString();
    const checkInFrom = new Date(
        starts - limits.checkInOpensMinutes * 60_000,
    ).toISOString();
    const checkInBy = new Date(
        starts + limits.noShowGraceMinutes * 60_000,
    ).toISOString();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl">
                    You are booked, {reservation.name}
                </CardTitle>
                <CardDescription>
                    Here is what we are keeping for you.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                <dl className="divide-y rounded-lg border text-sm">
                    {[
                        ['Date', formatDate(reservation.date)],
                        [
                            'Time',
                            `${formatTime(reservation.startsAt)} – ${formatTime(endsAt)} (${formatSessionLength(reservation.minutes)})`,
                        ],
                        [
                            'Group',
                            `${reservation.partySize} ${reservation.partySize === 1 ? 'person' : 'people'}`,
                        ],
                        [
                            'Lanes',
                            `${reservation.lanes} ${reservation.lanes === 1 ? 'lane' : 'lanes'}`,
                        ],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="flex justify-between gap-4 p-3"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="text-right font-medium tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <p className="text-sm text-muted-foreground">
                    Check in at the counter between {formatTime(checkInFrom)}{' '}
                    and {formatTime(checkInBy)}. If you have not checked in by
                    then, your{' '}
                    {reservation.lanes === 1 ? 'lane goes' : 'lanes go'} to
                    other players.
                </p>

                <Button variant="outline" className="w-full" onClick={onAgain}>
                    Make another reservation
                </Button>
            </CardContent>
        </Card>
    );
}

export default function ReserveLane({
    session,
    limits,
}: {
    session: SessionRules;
    limits: Limits;
}) {
    const { name } = usePage().props;
    const [reservation, setReservation] = useState<Reservation | null>(null);

    return (
        <>
            <Head title="Reserve a lane" />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-md items-center p-4 sm:p-6">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 truncate font-semibold tracking-tight"
                    >
                        <ArrowLeft aria-hidden className="size-4 shrink-0" />
                        {name}
                    </Link>
                </header>

                <main className="mx-auto flex w-full max-w-md flex-1 flex-col gap-4 p-4 sm:p-6">
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        This is a sample page. Booking online is not switched on
                        yet, so nothing you enter here is saved and no lane is
                        kept for you.
                    </p>

                    {reservation ? (
                        <Reserved
                            reservation={reservation}
                            limits={limits}
                            onAgain={() => setReservation(null)}
                        />
                    ) : (
                        <ReserveForm
                            session={session}
                            limits={limits}
                            onReserve={setReservation}
                        />
                    )}
                </main>
            </div>
        </>
    );
}
