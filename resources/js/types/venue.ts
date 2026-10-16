// One day of the venue's opening hours, as "HH:MM" clock times in the
// venue's time zone. weekday is 0 for Sunday. Both times are null when the
// venue is closed that day. A closing time at or before the opening time is
// after midnight.
export type DayHours = {
    weekday: number;
    opens: string | null;
    closes: string | null;
};

// The words on the front page, as an admin has written them or the plain
// ones where they have not.
export type FrontPageTexts = {
    tagline: string;
    waitlistTitle: string;
    waitlistText: string;
    waitlistButton: string;
    reserveTitle: string;
    reserveText: string;
    reserveButton: string;
};

// The week's opening hours as the front page lists them, Monday first, with
// today's day of the week (0 is Sunday) on the venue's clock.
export type FrontPageOpeningHours = {
    days: DayHours[];
    today: number;
};

// Whether the venue is open right now, as ISO 8601 times in UTC. opensAt is
// set while closed, closesAt while open; both are null when no opening hours
// have been set, and the venue counts as always open.
export type OpeningStatus = {
    isOpen: boolean;
    opensAt: string | null;
    closesAt: string | null;
};
