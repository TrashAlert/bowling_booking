import { Form } from '@inertiajs/react';
import LaneSessionController from '@/actions/App/Http/Controllers/Staff/LaneSessionController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatLanes, formatTime } from '@/lib/format';
import type { LaneCard } from '@/types';

/**
 * Asks before ending the session on one lane early. A party on several lanes
 * keeps the others. The lane card only renders this while the session is
 * still running.
 */
export function EndSessionDialog({
    lane,
    onClose,
}: {
    lane: LaneCard;
    onClose: () => void;
}) {
    const current = lane.current;

    if (!current) {
        return null;
    }

    const otherLanes = current.partyLaneNumbers.filter(
        (number) => number !== lane.number,
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>
                    End{' '}
                    {current.customerName ? `${current.customerName}'s` : 'the'}{' '}
                    session on lane {lane.number}?
                </DialogTitle>
                <DialogDescription>
                    Lane {lane.number} will be free straight away instead of at{' '}
                    {formatTime(current.endsAt)}.{' '}
                    {otherLanes.length > 0 &&
                        `The party keeps ${formatLanes(otherLanes).toLowerCase()}. `}
                    This can't be undone.
                </DialogDescription>

                <Form
                    {...LaneSessionController.destroy.form(lane.id)}
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
                                Keep playing
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                End session
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
