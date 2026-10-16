import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatWait } from '@/lib/format';
import type { WalkInTicket } from '@/types';

/**
 * The QR code a walk-in scans at the counter to follow its place in line on
 * its own phone. Staff turn the screen round, so it speaks to the customer.
 */
export function WalkInTicketDialog({
    ticket,
    onClose,
}: {
    // The party to show the code for; null keeps the dialog shut.
    ticket: WalkInTicket | null;
    onClose: () => void;
}) {
    const wait =
        ticket?.estimatedWaitMinutes === undefined ||
        ticket.estimatedWaitMinutes === null
            ? null
            : formatWait(ticket.estimatedWaitMinutes);

    return (
        <Dialog
            open={ticket !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-sm">
                {ticket && (
                    <>
                        <DialogTitle>
                            Scan to follow your place in line
                        </DialogTitle>
                        <DialogDescription>
                            {ticket.name}, you are number {ticket.position} in
                            line
                            {wait && `. Estimated wait: ${wait}`}.
                        </DialogDescription>

                        {/* Black on white in dark mode too, so any camera reads it. */}
                        <img
                            src={WaitlistController.qrCode.url(ticket.id)}
                            alt={`QR code for ${ticket.name}'s place in line`}
                            width={256}
                            height={256}
                            className="mx-auto size-64 rounded-lg bg-white"
                        />

                        <p className="text-sm text-muted-foreground">
                            Point your phone's camera at the code. Your page
                            shows your place in line and the wait, counts down
                            when it is your turn, and can notify your phone.
                        </p>

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button>Done</Button>
                            </DialogClose>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
