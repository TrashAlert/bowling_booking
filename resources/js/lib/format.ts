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

/**
 * Show a calendar date ("2026-10-05") in brief with its year, e.g. "Mon, 5
 * Oct 2026".
 */
export function formatShortCalendarDate(date: string): string {
    return shortDateFormat.format(new Date(`${date}T12:00`));
}

const monthFormat = new Intl.DateTimeFormat(undefined, {
    month: 'long',
    year: 'numeric',
});

const shortMonthFormat = new Intl.DateTimeFormat(undefined, {
    month: 'short',
});

/**
 * Name the month a calendar date ("2026-10-05") falls in, e.g. "October
 * 2026", or "Oct" in brief.
 */
export function formatMonth(date: string, brief = false): string {
    return (brief ? shortMonthFormat : monthFormat).format(
        new Date(`${date}T12:00`),
    );
}

const dayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'long' });

/**
 * Show the day of the week a UTC timestamp falls on for the viewer, e.g.
 * "Saturday". Meant for dates a few days away.
 */
export function formatDay(iso: string): string {
    return dayFormat.format(new Date(iso));
}

const weekdayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'short' });

const dayMonthFormat = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
});

/**
 * Show a calendar date ("2026-10-05") as briefly as a chart needs under a
 * column: its weekday ("Mon"), or its day and month ("5 Oct") where the
 * chart covers more than a week.
 */
export function formatDateTick(date: string, withinAWeek: boolean): string {
    return (withinAWeek ? weekdayFormat : dayMonthFormat).format(
        new Date(`${date}T12:00`),
    );
}

const longWeekdayFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
});

/**
 * Name a day of the week (0 is Sunday), e.g. "Mon", or "Monday" in full.
 */
export function formatWeekday(weekday: number, long = false): string {
    // 4 October 2026 was a Sunday.
    return (long ? longWeekdayFormat : weekdayFormat).format(
        new Date(2026, 9, 4 + weekday, 12),
    );
}

const hourFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric' });

/**
 * Show an hour of the day (0 to 23) the way the viewer's device writes
 * times, e.g. "7 PM".
 */
export function formatHour(hour: number): string {
    return hourFormat.format(new Date(2000, 0, 1, hour));
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
 * A stretch of time that may run to days, from its length in minutes, e.g.
 * "45 min", "5 h 30 min" or "2 d 4 h".
 */
export function formatDuration(minutes: number): string {
    if (minutes < 24 * 60) {
        return formatMinutes(minutes * 60_000);
    }

    const days = Math.floor(minutes / (24 * 60));
    const hours = Math.floor((minutes % (24 * 60)) / 60);

    return hours === 0 ? `${days} d` : `${days} d ${hours} h`;
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
 * When something happens, from the viewer's point of view: "today at
 * 10:00 AM", "tomorrow at 10:00 AM" or "on Monday at 10:00 AM".
 */
export function formatWhen(iso: string, now: Date = new Date()): string {
    const moment = new Date(iso);
    const days = Math.round(
        (new Date(moment).setHours(0, 0, 0, 0) -
            new Date(now).setHours(0, 0, 0, 0)) /
            86_400_000,
    );
    const day =
        days === 0 ? 'today' : days === 1 ? 'tomorrow' : `on ${formatDay(iso)}`;

    return `${day} at ${formatTime(iso)}`;
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
