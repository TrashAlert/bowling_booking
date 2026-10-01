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
import { closureLabels } from '@/lib/closures';
import { formatSessionLength, formatTime } from '@/lib/format';
import type { ClosureOptions, LaneCard } from '@/types';

/**
 * Keeps a lane closed a little longer when a short job is overrunning. The
 * lane card only renders this while that closure is under way.
 */
export function AddClosureTimeDialog({
    lane,
    options,
    onClose,
}: {
    lane: LaneCard;
    options: ClosureOptions;
    onClose: () => void;
}) {
    const [minutes, setMinutes] = useState(options.minutes[0]);

    const current = lane.current;

    if (!current?.closureReason) {
        return null;
    }

    const newEnd = new Date(
        Date.parse(current.endsAt) + minutes * 60_000,
    ).toISOString();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>
                    Keep lane {lane.number} closed for longer
                </DialogTitle>
                <DialogDescription>
                    {closureLabels[current.closureReason]} is due to finish at{' '}
                    {formatTime(current.endsAt)}.
                </DialogDescription>

                <Form
                    {...LaneClosureController.update.form(lane.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 flex w-full items-baseline justify-between text-sm leading-none font-medium">
                                    Extra time
                                    <span className="font-normal text-muted-foreground">
                                        Reopens {formatTime(newEnd)}
                                    </span>
                                </legend>
                                <ChoicePills
                                    name="minutes"
                                    options={options.minutes.map((value) => ({
                                        value,
                                        label: formatSessionLength(value),
                                    }))}
                                    value={minutes}
                                    onChange={setMinutes}
                                />
                                <InputError message={errors.minutes} />
                            </fieldset>

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Add time
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
