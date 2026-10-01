import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import InputError from '@/components/input-error';
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
import { formatSessionLength } from '@/lib/format';
import type { SessionRules } from '@/types';

// The length that is ticked when the dialog opens, if the rules allow it.
const DEFAULT_SESSION_MINUTES = 60;

export function AddWalkInDialog({ session }: { session: SessionRules }) {
    const [open, setOpen] = useState(false);

    const lengths = Array.from(
        { length: Math.floor(session.maxMinutes / session.stepMinutes) },
        (_, index) => (index + 1) * session.stepMinutes,
    );
    const defaultLength = lengths.includes(DEFAULT_SESSION_MINUTES)
        ? DEFAULT_SESSION_MINUTES
        : lengths[0];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
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
                                    <Input
                                        id="walk-in-phone"
                                        name="phone"
                                        type="tel"
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
                                        defaultValue={2}
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
                                    Session length
                                </legend>
                                <div className="grid grid-cols-4 gap-2">
                                    {lengths.map((minutes) => (
                                        <label
                                            key={minutes}
                                            className="flex h-11 cursor-pointer items-center justify-center rounded-lg border text-sm font-medium tabular-nums has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                                        >
                                            <input
                                                type="radio"
                                                name="minutes"
                                                value={minutes}
                                                defaultChecked={
                                                    minutes === defaultLength
                                                }
                                                required
                                                className="sr-only"
                                            />
                                            {formatSessionLength(minutes)}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.minutes} />
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
