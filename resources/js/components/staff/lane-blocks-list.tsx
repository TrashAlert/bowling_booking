import { Form } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import LaneBlockController from '@/actions/App/Http/Controllers/Staff/LaneBlockController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatLanes, formatTime } from '@/lib/format';
import type { LaneBlockRow } from '@/types';

/**
 * Asks before freeing a block's lanes. One under way ends now.
 */
function RemoveBlockDialog({
    block,
    now,
    onClose,
}: {
    block: LaneBlockRow;
    now: number;
    onClose: () => void;
}) {
    const underWay = Date.parse(block.startsAt) <= now;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>
                    {underWay ? 'End' : 'Remove'} the {block.note} block?
                </DialogTitle>
                <DialogDescription>
                    {formatLanes(block.lanes.map((lane) => lane.number))}{' '}
                    {underWay
                        ? 'are free again from now.'
                        : 'go back to the waitlist and reservations.'}{' '}
                    If it repeats weekly, the other weeks stay blocked.
                </DialogDescription>
                <Form
                    {...LaneBlockController.destroy.form(block.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={onClose}
                            >
                                Keep it
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {underWay ? 'End it now' : 'Remove block'}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The lanes blocked on the day the Reservations page shows, earliest first,
 * each with a way to free them while it isn't over.
 */
export function LaneBlocksList({
    blocks,
    now,
}: {
    blocks: LaneBlockRow[];
    now: number;
}) {
    const [removingId, setRemovingId] = useState<number | null>(null);
    const removing = blocks.find((block) => block.id === removingId);

    if (blocks.length === 0) {
        return null;
    }

    return (
        <section aria-label="Blocked lanes">
            <ul className="divide-y rounded-xl border bg-card text-card-foreground shadow-sm">
                {blocks.map((block) => {
                    const over = Date.parse(block.endsAt) <= now;

                    return (
                        <li
                            key={block.id}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4"
                        >
                            <div className="w-36 shrink-0">
                                <p className="font-medium tabular-nums">
                                    {formatTime(block.startsAt)} –{' '}
                                    {formatTime(block.endsAt)}
                                </p>
                            </div>

                            <div className="min-w-0 flex-1 basis-56">
                                <p className="flex items-center gap-2 font-medium">
                                    <Ban
                                        aria-hidden
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span className="truncate">
                                        {block.note}
                                    </span>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {formatLanes(
                                        block.lanes.map((lane) => lane.number),
                                    )}{' '}
                                    blocked
                                    {block.keptClearFrom !== block.startsAt &&
                                        `, kept clear from ${formatTime(block.keptClearFrom)}`}
                                    {block.createdBy &&
                                        ` · by ${block.createdBy}`}
                                </p>
                            </div>

                            {!over && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => setRemovingId(block.id)}
                                >
                                    {Date.parse(block.startsAt) <= now
                                        ? 'End now'
                                        : 'Remove'}
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>

            {removing && (
                <RemoveBlockDialog
                    block={removing}
                    now={now}
                    onClose={() => setRemovingId(null)}
                />
            )}
        </section>
    );
}
