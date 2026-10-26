import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistJoinController from '@/actions/App/Http/Controllers/WaitlistJoinController';
import InputError from '@/components/input-error';
import PhoneInput from '@/components/phone-input';
import { SessionChoicePicker } from '@/components/staff/session-choice-picker';
import { defaultSessionLength } from '@/components/staff/session-length-picker';
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
import { useServerClock } from '@/hooks/use-server-clock';
import {
    formatMoney,
    formatSessionLength,
    formatTime,
    formatWait,
    formatWhen,
} from '@/lib/format';
import { choiceMinutes, defaultChoice } from '@/lib/sessions';
import type {
    OnlineWaitlistStatus,
    OpeningStatus,
    SessionRules,
} from '@/types';

// How often the page asks again how long the wait is.
const POLL_INTERVAL_MS = 30_000;

/**
 * Why a group can't join online for the session it picked, given its likely
 * wait: the wait is longer than the venue takes online, or the session would
 * end after closing time. Null while it can join.
 */
function joinLimitReached(
    limits: { finishByClosing: boolean; maxWaitMinutes: number | null },
    wait: number | null | undefined,
    minutes: number,
    closesAt: string | null,
    now: number,
): { title: string; detail: string } | null {
    if (wait === null || wait === undefined) {
        return null;
    }

    if (limits.maxWaitMinutes !== null && wait > limits.maxWaitMinutes) {
        return {
            title: `The wait is over ${formatSessionLength(limits.maxWaitMinutes)} right now`,
            detail: 'We are not adding more groups online until it is shorter. Please ask our staff at the counter.',
        };
    }

    if (
        limits.finishByClosing &&
        closesAt !== null &&
        now + (wait + minutes) * 60_000 > Date.parse(closesAt)
    ) {
        return {
            title: `We close at ${formatTime(closesAt)}`,
            detail: 'A session that long, joined now, would end after closing time. Pick a shorter one, or ask our staff at the counter.',
        };
    }

    return null;
}

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
 * What the page shows instead of the form while nobody can join: the venue
 * is closed, or an admin has closed joining online.
 */
function Closed({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <>
            <Head title="Join the waitlist" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">{title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
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
    deviceLimit,
    opening,
    online,
    joinLimits,
    serverNow,
}: {
    session: SessionRules;
    checkInMinutes: number;
    // What a party that has to wait pays; zero while the deposit is off.
    depositCents: number;
    // The likely wait in minutes for each session length on offer.
    waitMinutes: Record<string, number | null>;
    // False while every lane is out of order, when nobody can join.
    lanesOpen: boolean;
    // How many groups one phone may have in the line at once, and whether
    // this phone already has that many.
    deviceLimit: { max: number; reached: boolean };
    // Joining is only possible while the venue is open.
    opening: OpeningStatus;
    // And while an admin lets customers join online at all.
    online: OnlineWaitlistStatus;
    // Whether a session must end by closing time, and the longest wait at
    // which a group can still join; null for no limit.
    joinLimits: { finishByClosing: boolean; maxWaitMinutes: number | null };
    serverNow: string;
}) {
    const { currencySymbol } = usePage().props;
    const deposit = formatMoney(depositCents, currencySymbol);
    const [choice, setChoice] = useState(() =>
        defaultChoice(session, defaultSessionLength(session)),
    );
    const [partySize, setPartySize] = useState('2');
    // How long the lane would be kept: the length picked, or the time the
    // games picked are expected to take. The wait is looked up for that.
    const minutes = choiceMinutes(session, choice, Number(partySize));
    // No deposit is asked for while a lane is free for the session picked,
    // or at all while the venue has the deposit turned off.
    const laneIsFree = waitMinutes[minutes] === 0;
    const depositAsked = depositCents > 0;
    // Nothing to join, or pay for, while no lane can be found for the session.
    const noLane = !lanesOpen || waitMinutes[minutes] === null;
    const now = useServerClock(serverNow);
    // Why a group can't join online for the session picked, by the limits
    // an admin set; null when it can. The server checks again on joining.
    const limitReached = joinLimitReached(
        joinLimits,
        waitMinutes[minutes],
        minutes,
        opening.closesAt,
        now,
    );

    usePoll(POLL_INTERVAL_MS, {
        only: [
            'waitMinutes',
            'lanesOpen',
            'deviceLimit',
            'opening',
            'online',
            'joinLimits',
            'serverNow',
        ],
    });

    if (!online.open) {
        return (
            <Closed
                title="Joining online is closed"
                description={online.message}
            />
        );
    }

    if (!opening.isOpen) {
        return (
            <Closed
                title="We are closed right now"
                description={
                    opening.opensAt
                        ? `We open again ${formatWhen(opening.opensAt)}. You can join the waitlist then.`
                        : 'Please check back later.'
                }
            />
        );
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
                                            value={partySize}
                                            onChange={(event) =>
                                                setPartySize(event.target.value)
                                            }
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
                                        {session.mode === 'games'
                                            ? 'How many games do you want to play?'
                                            : 'How long do you want to play?'}
                                    </legend>
                                    <SessionChoicePicker
                                        session={session}
                                        value={choice}
                                        onChange={setChoice}
                                        partySize={Number(partySize)}
                                    />
                                    <InputError
                                        message={errors.minutes ?? errors.games}
                                    />
                                </fieldset>

                                <WaitEstimate
                                    minutes={waitMinutes[minutes]}
                                    lanesOpen={lanesOpen}
                                />

                                {noLane ||
                                !depositAsked ||
                                limitReached !== null ? null : laneIsFree ? (
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

                                {!noLane && limitReached && (
                                    <div className="rounded-lg border p-3 text-sm">
                                        <p className="font-medium">
                                            {limitReached.title}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {limitReached.detail}
                                        </p>
                                    </div>
                                )}

                                {deviceLimit.reached && (
                                    <div className="rounded-lg border p-3 text-sm">
                                        <p className="font-medium">
                                            This phone already has{' '}
                                            {deviceLimit.max}{' '}
                                            {deviceLimit.max === 1
                                                ? 'group'
                                                : 'groups'}{' '}
                                            in the line
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            Leave{' '}
                                            {deviceLimit.max === 1
                                                ? 'it'
                                                : 'one of them'}{' '}
                                            first, or ask our staff at the
                                            counter to add your group.
                                        </p>
                                    </div>
                                )}

                                <InputError
                                    message={errors.closed ?? errors.limit}
                                />

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={
                                        processing ||
                                        noLane ||
                                        limitReached !== null ||
                                        deviceLimit.reached
                                    }
                                >
                                    {laneIsFree || noLane || !depositAsked
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
