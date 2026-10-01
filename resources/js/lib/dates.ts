// Dates and times as staff type them, in the time zone of the device in use.
// The server only ever receives and sends UTC.

const pad = (value: number) => String(value).padStart(2, '0');

/**
 * The time zone of this device, e.g. "Asia/Kuala_Lumpur".
 */
export function browserTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/**
 * A moment as the value of a date input ("2026-10-05"), in local time.
 */
export function toDateInput(moment: Date): string {
    return `${moment.getFullYear()}-${pad(moment.getMonth() + 1)}-${pad(moment.getDate())}`;
}

/**
 * A moment as the value of a time input ("19:30"), in local time.
 */
export function toTimeInput(moment: Date): string {
    return `${pad(moment.getHours())}:${pad(moment.getMinutes())}`;
}

/**
 * Move a date input value by a number of days.
 */
export function addDays(date: string, days: number): string {
    const moment = new Date(`${date}T12:00`);

    moment.setDate(moment.getDate() + days);

    return toDateInput(moment);
}

/**
 * A local date and time as a UTC ISO string, or null while either is empty.
 */
export function localToIso(date: string, time: string): string | null {
    const moment = new Date(`${date}T${time}`);

    return Number.isNaN(moment.getTime()) ? null : moment.toISOString();
}

/**
 * Every time of day a session may start at, as time input values.
 */
export function startTimes(stepMinutes: number): string[] {
    return Array.from(
        { length: Math.floor((24 * 60) / stepMinutes) },
        (_, index) =>
            `${pad(Math.floor((index * stepMinutes) / 60))}:${pad((index * stepMinutes) % 60)}`,
    );
}

/**
 * The first start time at or after the given moment, with its date. Late in
 * the evening this is the next day.
 */
export function nextStartTime(
    moment: Date,
    stepMinutes: number,
): { date: string; time: string } {
    const step = stepMinutes * 60_000;
    const next = new Date(Math.ceil(moment.getTime() / step) * step);

    return { date: toDateInput(next), time: toTimeInput(next) };
}
