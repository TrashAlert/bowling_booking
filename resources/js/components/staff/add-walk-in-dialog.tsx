import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import InputError from '@/components/input-error';
import { SessionLengthPicker } from '@/components/staff/session-length-picker';
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
import type { SessionRules } from '@/types';

export function AddWalkInDialog({ session }: { session: SessionRules }) {
    const [open, setOpen] = useState(false);

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
                                <SessionLengthPicker session={session} />
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
