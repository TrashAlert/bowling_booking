const timeFormat = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});

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
 * A ticking countdown, e.g. "4:05". Never goes below "0:00".
 */
export function formatCountdown(milliseconds: number): string {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
