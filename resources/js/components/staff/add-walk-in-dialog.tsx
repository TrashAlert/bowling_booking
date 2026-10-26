import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import InputError from '@/components/input-error';
import PhoneInput from '@/components/phone-input';
import { SessionChoicePicker } from '@/components/staff/session-choice-picker';
import { defaultSessionLength } from '@/components/staff/session-length-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { defaultChoice } from '@/lib/sessions';
import type { SessionRules } from '@/types';

export function AddWalkInDialog({ session }: { session: SessionRules }) {
    const [open, setOpen] = useState(false);
    const [partySize, setPartySize] = useState('2');
    const [choice, setChoice] = useState(() =>
        defaultChoice(session, defaultSessionLength(session)),
    );

    // Each new walk-in starts from the usual party size and length.
    const openDialog = (next: boolean) => {
        if (next) {
            setPartySize('2');
            setChoice(defaultChoice(session, defaultSessionLength(session)));
        }

        setOpen(next);
    };

    return (
        <Dialog open={open} onOpenChange={openDialog}>
            <DialogTrigger asChild>
                <Button>Add walk-in</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Add a walk-in party</DialogTitle>
                <DialogDescription>
                    They join the end of the line and are called when a lane is
                    free for their whole session.
                </DialogDescription>

                <Form
                    {...WaitlistController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="walk-in-name">Name</Label>
                                <Input
                                    id="walk-in-name"
                                    name="name"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid grid-cols-2 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="walk-in-phone">
                                        Phone (optional)
                                    </Label>
                                    <PhoneInput
                                        id="walk-in-phone"
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="walk-in-party-size">
                                        Party size
                                    </Label>
                                    <Input
                                        id="walk-in-party-size"
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
                                    <p className="text-xs text-muted-foreground">
                                        Up to {session.maxPlayersPerLane} per
                                        lane. Add another walk-in for a bigger
                                        group.
                                    </p>
                                    <InputError message={errors.party_size} />
                                </div>
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    {session.mode === 'games'
                                        ? 'Games'
                                        : 'Session length'}
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

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Add to line
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
