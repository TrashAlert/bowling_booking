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
import { formatSessionLength } from '@/lib/format';
import { home } from '@/routes';
import type { SessionRules } from '@/types';

// What the party typed in, kept only on this screen.
type Party = {
    name: string;
    partySize: number;
    minutes: number;
};

// A made-up place in line, to show what a waiting party would see.
const SAMPLE_POSITION = 3;

function JoinForm({
    session,
    onJoin,
}: {
    session: SessionRules;
    onJoin: (party: Party) => void;
}) {
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const form = new FormData(event.currentTarget);
        const name = form.get('name');

        onJoin({
            name: typeof name === 'string' ? name.trim() : '',
            partySize: Number(form.get('party_size')),
            minutes: Number(form.get('minutes')),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl">Join the waitlist</CardTitle>
                <CardDescription>
                    Add your group to the line. We will call your name when a
                    lane is ready.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid gap-2">
                        <Label htmlFor="waitlist-name">Name</Label>
                        <Input
                            id="waitlist-name"
                            name="name"
                            required
                            maxLength={255}
                            autoComplete="name"
                        />
                    </div>

                    <div className="grid grid-cols-2 items-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="waitlist-phone">
                                Phone (optional)
                            </Label>
                            <Input
                                id="waitlist-phone"
                                name="phone"
                                type="tel"
                                maxLength={30}
                                autoComplete="tel"
                            />
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
                        </div>
                    </div>
                    <p className="-mt-3 text-xs text-muted-foreground">
                        Up to {session.maxPlayersPerLane} people share a lane. A
                        bigger group joins once for each lane it needs.
                    </p>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm leading-none font-medium">
                            How long do you want to play?
                        </legend>
                        <SessionLengthPicker session={session} />
                    </fieldset>

                    <Button type="submit" className="w-full">
                        Join the waitlist
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function InLine({
    party,
    checkInMinutes,
    onLeave,
}: {
    party: Party;
    checkInMinutes: number;
    onLeave: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl">
                    You are in line, {party.name}
                </CardTitle>
                <CardDescription>
                    {party.partySize}{' '}
                    {party.partySize === 1 ? 'person' : 'people'} ·{' '}
                    {formatSessionLength(party.minutes)}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="rounded-lg border p-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        Your place in line
                    </p>
                    <p className="text-6xl font-semibold tabular-nums">
                        {SAMPLE_POSITION}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {SAMPLE_POSITION - 1} groups ahead of you
                    </p>
                </div>

                <p className="text-sm text-muted-foreground">
                    Stay close by. When we call your name you have{' '}
                    {checkInMinutes} minutes to check in at the counter, or your
                    lane goes to the next group.
                </p>

                <Button variant="outline" className="w-full" onClick={onLeave}>
                    Leave the line
                </Button>
            </CardContent>
        </Card>
    );
}

export default function WaitlistJoin({
    session,
    checkInMinutes,
}: {
    session: SessionRules;
    checkInMinutes: number;
}) {
    const { name } = usePage().props;
    const [party, setParty] = useState<Party | null>(null);

    return (
        <>
            <Head title="Join the waitlist" />

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
                        This is a sample page. Joining online is not switched on
                        yet, so nothing you enter here is saved and the place in
                        line is made up.
                    </p>

                    {party ? (
                        <InLine
                            party={party}
                            checkInMinutes={checkInMinutes}
                            onLeave={() => setParty(null)}
                        />
                    ) : (
                        <JoinForm session={session} onJoin={setParty} />
                    )}
                </main>
            </div>
        </>
    );
}
