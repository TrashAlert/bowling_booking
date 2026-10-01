import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BookingExtensionController from '@/actions/App/Http/Controllers/Staff/BookingExtensionController';
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
import { formatSessionLength, formatTime } from '@/lib/format';
import type { LaneCard, SessionRules } from '@/types';

/**
 * Gives the party playing on a lane more time. The lane card only shows this
 * while the session is still running, so it closes itself when time runs out.
 */
export function ExtendSessionDialog({
    lane,
    session,
}: {
    lane: LaneCard;
    session: SessionRules;
}) {
    const [open, setOpen] = useState(false);
    const [minutes, setMinutes] = useState(session.stepMinutes);

    const current = lane.current;

    if (!current || current.bookingId === null) {
        return null;
    }

    const room = current.extendableMinutes;
    const noRoom = room !== null && room < session.stepMinutes;
    const newEnd = new Date(
        Date.parse(current.endsAt) + minutes * 60_000,
    ).toISOString();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-7 shrink-0 px-2 text-xs"
                >
                    Extend
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Extend {current.customerName ?? `lane ${lane.number}`}'s
                    session
                </DialogTitle>
                <DialogDescription>
                    It ends at {formatTime(current.endsAt)} now.{' '}
                    {noRoom
                        ? 'A lane is booked again straight after, so there is no room to extend.'
                        : room !== null
                          ? `A lane is booked again after that, so it can be extended by up to ${formatSessionLength(room)}.`
                          : 'Every lane of the party gets the extra time.'}
                </DialogDescription>

                <Form
                    {...BookingExtensionController.store.form(
                        current.bookingId,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            {!noRoom && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 flex w-full items-baseline justify-between text-sm leading-none font-medium">
                                        Extra time
                                        <span className="font-normal text-muted-foreground">
                                            New end {formatTime(newEnd)}
                                        </span>
                                    </legend>
                                    <SessionLengthPicker
                                        session={session}
                                        value={minutes}
                                        onChange={setMinutes}
                                        maxMinutes={room ?? undefined}
                                    />
                                    <InputError message={errors.minutes} />
                                </fieldset>
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing || noRoom}
                                >
                                    Extend session
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
