import { usePage } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';

/**
 * The announcement an admin put up under Settings, Front page, across the top
 * of a customer page, in the brand colour. Nothing while there is none, or
 * once its last day has passed.
 */
export default function AnnouncementBanner() {
    const { announcement } = usePage().props;

    if (announcement === null) {
        return null;
    }

    return (
        <div
            role="note"
            aria-label="Announcement"
            className="flex items-start justify-center gap-2 bg-primary px-4 py-2.5 text-center text-sm font-medium text-primary-foreground"
        >
            <Megaphone aria-hidden className="mt-0.5 size-4 shrink-0" />
            <p className="whitespace-pre-line">{announcement}</p>
        </div>
    );
}
