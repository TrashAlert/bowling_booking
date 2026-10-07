import { usePage } from '@inertiajs/react';
import { MapPin, Phone } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * How to reach the business, for the bottom of the customer pages: its
 * address, a phone number to tap and a link to a map. Each shows only if an
 * admin has filled it in under Settings; with none, nothing is shown at all.
 */
export default function ContactDetails({ className }: { className?: string }) {
    const { contact } = usePage().props;

    if (!contact.phone && !contact.address && !contact.mapUrl) {
        return null;
    }

    return (
        <address
            className={cn(
                'space-y-1 text-center text-sm text-muted-foreground not-italic',
                className,
            )}
        >
            {contact.address && (
                <p className="whitespace-pre-line">{contact.address}</p>
            )}

            {(contact.phone || contact.mapUrl) && (
                <p className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
                    {contact.phone && (
                        <a
                            // Dialled without the spaces and brackets it is written with.
                            href={`tel:${contact.phone.replace(/[^0-9+]/g, '')}`}
                            className="inline-flex items-center gap-1 text-foreground tabular-nums underline-offset-4 hover:underline"
                        >
                            <Phone aria-hidden className="size-3.5" />
                            {contact.phone}
                        </a>
                    )}
                    {contact.mapUrl && (
                        <a
                            href={contact.mapUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-foreground underline-offset-4 hover:underline"
                        >
                            <MapPin aria-hidden className="size-3.5" />
                            View on map
                        </a>
                    )}
                </p>
            )}
        </address>
    );
}
