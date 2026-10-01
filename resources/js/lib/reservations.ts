import { browserTimeZone } from '@/lib/dates';
import { index } from '@/routes/staff/reservations';

/**
 * The Reservations page for a day, read in this device's time zone. Without a
 * date it opens on today.
 */
export function reservationsPage(date?: string) {
    return index({
        query: date
            ? { date, tz: browserTimeZone() }
            : { tz: browserTimeZone() },
    });
}
