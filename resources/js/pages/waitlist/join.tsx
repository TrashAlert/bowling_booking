import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistJoinController from '@/actions/App/Http/Controllers/WaitlistJoinController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, formatWait, formatWhen } from '@/lib/format';
import type { OpeningStatus, SessionRules } from '@/types';

// How often the page asks again how long the wait is.
const POLL_INTERVAL_MS = 30_000;

/**
 * How long a party joining now is likely to wait, for the session length it
 * has picked. Null means no lane could be found for it, so it can't join:
 * every lane is closed, or none is free for that long.
 */
function WaitEstimate({
    minutes,
    lanesOpen,
}: {
    minutes: number | null | undefined;
    lanesOpen: boolean;
}) {
    if (!lanesOpen) {
        return (
            <div className="rounded-lg border p-3 text-sm">
                <p className="font-medium">
                    All our lanes are closed for maintenance right now
                </p>
                <p className="mt-1 text-muted-foreground">
                    Please check back later, or ask our staff at the counter.
                </p>
            </div>
        );
    }

    if (minutes === undefined) {
        return null;
    }

    return (
        <div className="rounded-lg border p-3 text-sm">
            <p className="font-medium">
                {minutes === null
                    ? 'We cannot find a lane for that session right now'
                    : minutes === 0
                      ? 'A lane is free right now'
                      : `Estimated wait: ${formatWait(minutes)}`}
            </p>
            <p className="mt-1 text-muted-foreground">
                {minutes === null
                    ? 'Try a shorter session, or ask our staff at the counter.'
                    : minutes === 0
                      ? 'Join now and we will call you straight away.'
                      : 'Only an estimate. It assumes everyone ahead of you plays their full time.'}
            </p>
        </div>
    );
}

/**
 * What the page shows instead of the form while the venue is closed.
 */
function Closed({ opensAt }: { opensAt: string | null }) {
    return (
        <>
            <Head title="Join the waitlist" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">
                        We are closed right now
                    </CardTitle>
                    <CardDescription>
                        {opensAt
                            ? `We open again ${formatWhen(opensAt)}. You can join the waitlist then.`
                            : 'Please check back later.'}
                    </CardDescription>
                </CardHeader>
            </Card>
        </>
    );
}

/**
 * Where a party adds itself to the waitlist. While a lane is free for it,
 * sending the form puts it in line at once with nothing to pay. Otherwise it
 * pays a deposit on the next page first. While no lane can be found for the
 * session it can't join at all. The server decides which as the form arrives;
 * what is shown here is as of the last refresh.
 */
export default function WaitlistJoin({
    session,
    checkInMinutes,
    depositCents,
    waitMinutes,
    lanesOpen,
    opening,
}: {
    session: SessionRules;
    checkInMinutes: number;
    depositCents: number;
    // The likely wait in minutes for each session length on offer.
    waitMinutes: Record<string, number | null>;
    // False while every lane is out of order, when nobody can join.
    lanesOpen: boolean;
    // Joining is only possible while the venue is open.
    opening: OpeningStatus;
}) {
    const { currencySymbol } = usePage().props;
    const deposit = formatMoney(depositCents, currencySymbol);
    const [minutes, setMinutes] = useState(() => defaultSessionLength(session));
    // No deposit is asked for while a lane is free for the session picked.
    const laneIsFree = waitMinutes[minutes] === 0;
    // Nothing to join, or pay for, while no lane can be found for the session.
    const noLane = !lanesOpen || waitMinutes[minutes] === null;

    usePoll(POLL_INTERVAL_MS, {
        only: ['waitMinutes', 'lanesOpen', 'opening'],
    });

    if (!opening.isOpen) {
        return <Closed opensAt={opening.opensAt} />;
    }

    return (
        <>
            <Head title="Join the waitlist" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">Join the waitlist</CardTitle>
                    <CardDescription>
                        Add your group to the line and we will call you when a
                        lane is ready. You then have {checkInMinutes} minutes to
                        check in at the counter, so join when you are here or
                        nearly here.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...WaitlistJoinController.store.form()}
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="waitlist-name">Name</Label>
                                    <Input
                                        id="waitlist-name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid grid-cols-2 items-start gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="waitlist-phone">
                                            Phone
                                        </Label>
                                        <PhoneInput
                                            id="waitlist-phone"
                                            required
                                            autoComplete="tel"
                                        />
                                        <InputError message={errors.phone} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="waitlist-party-size">
                                            How many people
                                        </Label>
                                        <Input
                                            id="waitlist-party-size"
                                            name="party_size"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={session.maxPlayersPerLane}
                                            defaultValue={2}
                                            required
                                        />
                                        <InputError
                                            message={errors.party_size}
                                        />
                                    </div>
                                </div>
                                <p className="-mt-3 text-xs text-muted-foreground">
                                    Up to {session.maxPlayersPerLane} people
                                    share a lane. A bigger group joins once for
                                    each lane it needs.
                                </p>

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

                                <WaitEstimate
                                    minutes={waitMinutes[minutes]}
                                    lanesOpen={lanesOpen}
                                />

                                {noLane ? null : laneIsFree ? (
                                    <div className="rounded-lg border p-3 text-sm">
                                        <p className="font-medium">
                                            No deposit needed right now
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            A deposit of {deposit} is only asked
                                            for when every lane is busy.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="rounded-lg border p-3 text-sm">
                                        <p className="font-medium">
                                            No lane is free right now, so a{' '}
                                            {deposit} deposit is needed to join
                                            online
                                        </p>
                                        <ul className="mt-1 list-disc space-y-1 pl-5 text-muted-foreground">
                                            <li>
                                                It comes off your bill when you
                                                play.
                                            </li>
                                            <li>
                                                Leave the line before you are
                                                called and it is returned to
                                                you.
                                            </li>
                                            <li>
                                                If you are called and do not
                                                check in within {checkInMinutes}{' '}
                                                minutes, it is kept.
                                            </li>
                                        </ul>
                                    </div>
                                )}

                                <InputError message={errors.closed} />

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing || noLane}
                                >
                                    {laneIsFree || noLane
                                        ? 'Join the waitlist'
                                        : `Continue to pay ${deposit}`}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </>
    );
}
