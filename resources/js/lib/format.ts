const timeFormat = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});

const dateFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

/**
 * Show a calendar date ("2026-10-05") in words, e.g. "Monday, 5 October".
 */
export function formatDate(date: string): string {
    return dateFormat.format(new Date(`${date}T12:00`));
}

const shortDateFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/**
 * Show the date a UTC timestamp falls on for the viewer, in brief, e.g.
 * "Mon, 5 Oct 2026". For lists that mix several days.
 */
export function formatShortDate(iso: string): string {
    return shortDateFormat.format(new Date(iso));
}

const dayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'long' });

/**
 * Show the day of the week a UTC timestamp falls on for the viewer, e.g.
 * "Saturday". Meant for dates a few days away.
 */
export function formatDay(iso: string): string {
    return dayFormat.format(new Date(iso));
}

/**
 * Show a time of day ("19:30") the way the viewer's device writes times.
 */
export function formatTimeOfDay(time: string): string {
    return timeFormat.format(new Date(`2000-01-01T${time}`));
}

/**
 * Show a UTC timestamp as a clock time in the viewer's own time zone.
 */
export function formatTime(iso: string): string {
    return timeFormat.format(new Date(iso));
}

/**
 * A rough length of time for reading at a glance, e.g. "45 min" or "1 h 5 min".
 */
export function formatMinutes(milliseconds: number): string {
    const minutes = Math.max(0, Math.round(milliseconds / 60_000));

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const rest = minutes % 60;

    return rest === 0
        ? `${Math.floor(minutes / 60)} h`
        : `${Math.floor(minutes / 60)} h ${rest} min`;
}

/**
 * How long a session lasts, from its length in minutes, e.g. "1 h 30 min".
 */
export function formatSessionLength(minutes: number): string {
    return formatMinutes(minutes * 60_000);
}

/**
 * Name the lanes a party is on, e.g. "Lane 4" or "Lanes 5, 6 & 7".
 */
export function formatLanes(numbers: number[]): string {
    if (numbers.length <= 1) {
        return `Lane ${numbers.join('')}`;
    }

    return `Lanes ${numbers.slice(0, -1).join(', ')} & ${numbers.at(-1)}`;
}

/**
 * A wait as a customer is told it, rounded up to five minutes so it doesn't
 * promise more than an estimate can, e.g. "about 25 min".
 */
export function formatWait(minutes: number): string {
    return `about ${formatMinutes(Math.ceil(minutes / 5) * 5 * 60_000)}`;
}

/**
 * An amount of money kept in cents, e.g. "RM 10.00".
 */
export function formatMoney(cents: number, symbol: string): string {
    return `${symbol} ${(cents / 100).toFixed(2)}`;
}

/**
 * A ticking countdown, e.g. "4:05". Never goes below "0:00".
 */
export function formatCountdown(milliseconds: number): string {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
