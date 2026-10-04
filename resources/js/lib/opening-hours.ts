import { formatTimeOfDay } from '@/lib/format';
import type { DayHours } from '@/types';

const MINUTES_IN_A_DAY = 24 * 60;

/**
 * A clock time ("19:30") as minutes after midnight.
 */
function minutesOf(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

/**
 * A day's opening as minutes after its midnight, with a closing time after
 * midnight counted into the next day. Null when closed.
 */
function opening(day: DayHours | undefined): [number, number] | null {
    if (!day?.opens || !day.closes) {
        return null;
    }

    const opens = minutesOf(day.opens);
    const closes = minutesOf(day.closes);

    return [opens, closes <= opens ? closes + MINUTES_IN_A_DAY : closes];
}

/**
 * The day of the week a date input value ("2026-10-05") falls on, 0 for
 * Sunday.
 */
function weekdayOf(date: string): number {
    return new Date(`${date}T12:00`).getDay();
}

/**
 * Whether a session starting at time on date and lasting minutes falls
 * wholly within opening hours: in that day's opening, or in the previous
 * day's when that runs past midnight.
 *
 * The server checks the same rule in the venue's time zone; this reads the
 * date and time on the device's clock, which is the same for a customer at
 * or near the venue.
 */
export function fitsOpeningHours(
    week: DayHours[],
    date: string,
    time: string,
    minutes: number,
): boolean {
    const weekday = weekdayOf(date);
    const starts = minutesOf(time);
    const ends = starts + minutes;

    const today = opening(week.find((day) => day.weekday === weekday));

    if (today && starts >= today[0] && ends <= today[1]) {
        return true;
    }

    const yesterday = opening(
        week.find((day) => day.weekday === (weekday + 6) % 7),
    );

    return (
        yesterday !== null &&
        yesterday[1] > MINUTES_IN_A_DAY &&
        ends <= yesterday[1] - MINUTES_IN_A_DAY
    );
}

/**
 * The hours on the given date in words, e.g. "Open 10:00 AM to 11:00 PM",
 * or "Closed".
 */
export function hoursOn(week: DayHours[], date: string): string {
    const day = week.find((hours) => hours.weekday === weekdayOf(date));

    return day?.opens && day.closes
        ? `Open ${formatTimeOfDay(day.opens)} to ${formatTimeOfDay(day.closes)}`
        : 'Closed';
}
