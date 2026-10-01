import { Form } from '@inertiajs/react';
import { useState } from 'react';
import LaneClosureController from '@/actions/App/Http/Controllers/Staff/LaneClosureController';
import InputError from '@/components/input-error';
import { ChoicePills } from '@/components/staff/choice-pills';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { closureLabels, isShortClosure } from '@/lib/closures';
import { formatSessionLength, formatTime } from '@/lib/format';
import type { ClosureOptions, LaneCard, LaneClosureReason } from '@/types';

// The length that is ticked to begin with, if it is one of the presets.
const DEFAULT_CLOSURE_MINUTES = 30;

const reasons: { value: LaneClosureReason; hint: string }[] = [
    { value: 're_oil', hint: 'Reopens by itself' },
    { value: 'maintenance', hint: 'Reopens by itself' },
    { value: 'repair', hint: 'Until reopened' },
];

/**
 * Asks why a lane is being closed and for how long. A short job starts once
 * the lane is free and ends by itself; a repair closes the lane at once.
 */
export function CloseLaneDialog({
    lane,
    options,
    onClose,
}: {
    lane: LaneCard;
    options: ClosureOptions;
    onClose: () => void;
}) {
    const [reason, setReason] = useState<LaneClosureReason>('re_oil');
    const [minutes, setMinutes] = useState(
        options.minutes.includes(DEFAULT_CLOSURE_MINUTES)
            ? DEFAULT_CLOSURE_MINUTES
            : options.minutes[0],
    );
    // 0 stands for "until reopened": no estimate.
    const [days, setDays] = useState(0);

    const short = isShortClosure(reason);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>Close lane {lane.number}</DialogTitle>
                <DialogDescription>
                    Say why, so the board shows it and the lane comes back at
                    the right time.
                </DialogDescription>

                <Form
                    {...LaneClosureController.store.form(lane.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    Why
                                </legend>
                                <ChoicePills
                                    name="reason"
                                    className="grid-cols-3"
                                    options={reasons.map((option) => ({
                                        value: option.value,
                                        label: closureLabels[option.value],
                                        hint: option.hint,
                                    }))}
                                    value={reason}
                                    onChange={setReason}
                                />
                                <InputError message={errors.reason} />
                            </fieldset>

                            {short ? (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm leading-none font-medium">
                                        How long
                                    </legend>
                                    <ChoicePills
                                        name="minutes"
                                        options={options.minutes.map(
                                            (value) => ({
                                                value,
                                                label: formatSessionLength(
                                                    value,
                                                ),
                                            }),
                                        )}
                                        value={minutes}
                                        onChange={setMinutes}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {lane.current
                                            ? `Starts at ${formatTime(lane.current.endsAt)}, when what is on the lane now has finished.`
                                            : 'Starts now.'}{' '}
                                        {lane.next &&
                                            `The lane is booked again at ${formatTime(lane.next.startsAt)}.`}
                                    </p>
                                    <InputError message={errors.minutes} />
                                </fieldset>
                            ) : (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm leading-none font-medium">
                                        Expected back
                                    </legend>
                                    <ChoicePills
                                        name="days"
                                        options={[
                                            { value: 0, label: 'Not known' },
                                            ...options.repairDays.map(
                                                (value) => ({
                                                    value,
                                                    label: `${value} ${value === 1 ? 'day' : 'days'}`,
                                                }),
                                            ),
                                        ]}
                                        value={days}
                                        onChange={setDays}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Closed straight away, and it stays
                                        closed until someone reopens it.{' '}
                                        {lane.current?.isRunning &&
                                            'The party playing on it stays until its session ends. '}
                                        {lane.reservationsAhead > 0 &&
                                            `${lane.reservationsAhead} ${lane.reservationsAhead === 1 ? 'reservation' : 'reservations'} booked on this lane may need moving.`}
                                    </p>
                                    <InputError message={errors.days} />
                                </fieldset>
                            )}

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Close lane
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
