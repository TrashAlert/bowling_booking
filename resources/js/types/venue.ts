// One day of the venue's opening hours, as "HH:MM" clock times in the
// venue's time zone. weekday is 0 for Sunday. Both times are null when the
// venue is closed that day. A closing time at or before the opening time is
// after midnight.
export type DayHours = {
    weekday: number;
    opens: string | null;
    closes: string | null;
};

// Whether the venue is open right now, as ISO 8601 times in UTC. opensAt is
// set while closed, closesAt while open; both are null when no opening hours
// have been set, and the venue counts as always open.
export type OpeningStatus = {
    isOpen: boolean;
    opensAt: string | null;
    closesAt: string | null;
};
