import { browserTimeZone } from '@/lib/dates';
import { index } from '@/routes/staff/reservations';

/**
 * The Reservations page for a day, read in this device's time zone. Without a
 * date it opens on today. With a search term it lists the reservations that
 * match it on any day instead.
 */
export function reservationsPage(date?: string, search?: string) {
    return index({
        query: {
            ...(date ? { date } : {}),
            tz: browserTimeZone(),
            ...(search ? { search } : {}),
        },
    });
}
