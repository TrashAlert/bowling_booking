import { Form } from '@inertiajs/react';
import ReservationController from '@/actions/App/Http/Controllers/Staff/ReservationController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatLanes, formatTime } from '@/lib/format';
import type { ReservationDetail } from '@/types';

export function CancelReservationDialog({
    reservation,
    onClose,
}: {
    reservation: ReservationDetail;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogTitle>
                    Cancel {reservation.customerName}'s reservation?
                </DialogTitle>
                <DialogDescription>
                    {formatLanes(reservation.lanes.map((lane) => lane.number))}{' '}
                    at {formatTime(reservation.startsAt)} will be free again
                    straight away. This can't be undone.
                </DialogDescription>

                <Form
                    {...ReservationController.destroy.form(reservation.id)}
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
                                Cancel reservation
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
