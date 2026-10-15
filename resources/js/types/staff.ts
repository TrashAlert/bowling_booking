export type LaneStatus = 'open' | 'out_of_order';

export type LaneCardState =
    | 'free'
    | 'in_play'
    | 'held'
    | 'reserved'
    | 'closed_for_reservation'
    | 'maintenance'
    | 'blocked'
    | 'out_of_order';

export type WaitlistStatus =
    | 'waiting'
    | 'called'
    | 'seated'
    | 'skipped'
    | 'left';

export type BookingStatus =
    | 'pending'
    | 'confirmed'
    | 'checked_in'
    | 'completed'
    | 'cancelled'
    | 'no_show';

// Why a lane is closed. Re-oil and maintenance are short jobs after which the
// lane reopens by itself; a repair lasts until staff reopen the lane.
export type LaneClosureReason = 're_oil' | 'maintenance' | 'repair';

// The lengths and estimates staff can pick when closing a lane.
export type ClosureOptions = {
    minutes: number[];
    repairDays: number[];
};

// Every time on the board is an ISO 8601 string in UTC.
export type LaneCard = {
    id: number;
    number: number;
    hasBumpers: boolean;
    isOpen: boolean;
    state: LaneCardState;
    // Set while the lane is out of order: why, and when it is expected back.
    // The date is only an estimate; the lane stays closed until reopened.
    closure: {
        reason: LaneClosureReason | null;
        until: string | null;
    } | null;
    // Reservations still to check in that are booked on this lane.
    reservationsAhead: number;
    current: {
        bookingId: number | null;
        customerName: string | null;
        partySize: number | null;
        startsAt: string;
        endsAt: string;
        heldUntil: string | null;
        note: string | null;
        // True while a checked-in party is still playing on this lane. Only
        // such a session can be extended or ended early.
        isRunning: boolean;
        // How much longer it could play before a lane of its is booked again;
        // null when nothing is booked after it.
        extendableMinutes: number | null;
        // Every lane the party is on right now, this one included.
        partyLaneNumbers: number[];
        // Set when this is a short closure (re-oil, maintenance), not a booking.
        closureReason: LaneClosureReason | null;
    } | null;
    next: {
        startsAt: string;
        customerName: string | null;
        note: string | null;
        // True when the lane closes then, ahead of a reservation, not for play.
        isClosure: boolean;
        // Set when what comes next is a short closure waiting for the lane.
        closureReason: LaneClosureReason | null;
    } | null;
};

export type WaitlistRow = {
    id: number;
    position: number;
    customerName: string;
    partySize: number;
    minutes: number;
    status: Extract<WaitlistStatus, 'waiting' | 'called'>;
    joinedAt: string;
    calledAt: string | null;
    checkInBy: string | null;
    laneNumbers: number[];
    // True for a party that joined online; false for a walk-in added at the
    // counter.
    joinedOnline: boolean;
    // The deposit the party paid to join online, in cents, which comes off
    // its bill. Null if it paid none: a walk-in, or a lane was free as it
    // joined.
    depositCents: number | null;
};

export type ReservationRow = {
    id: number;
    customerName: string;
    phone: string | null;
    partySize: number;
    minutes: number;
    status: Extract<BookingStatus, 'confirmed' | 'checked_in'>;
    startsAt: string;
    endsAt: string;
    // Check-in is refused before this moment.
    checkInOpensAt: string;
    laneNumbers: number[];
    lanes: { id: number; number: number }[];
    // Lanes of a reservation still to check in that have been marked out of
    // order. The group has to be moved off them first.
    closedLaneNumbers: number[];
};

// A session's length is chosen in steps of stepMinutes, up to maxMinutes.
export type SessionRules = {
    stepMinutes: number;
    maxMinutes: number;
    maxPlayersPerLane: number;
};

// A reservation as listed on the Reservations page, whatever became of it.
export type ReservationDetail = {
    id: number;
    customerName: string;
    phone: string | null;
    partySize: number;
    minutes: number;
    status: BookingStatus;
    startsAt: string;
    endsAt: string;
    // Check-in is refused before this moment.
    checkInOpensAt: string;
    lanes: { id: number; number: number }[];
    notes: string | null;
    closedLaneNumbers: number[];
};

// What the Move lanes dialog needs to know about a reservation. Both the
// board's rows and the Reservations page's rows fit it.
export type MovableReservation = Pick<
    ReservationDetail,
    | 'id'
    | 'customerName'
    | 'startsAt'
    | 'endsAt'
    | 'lanes'
    | 'closedLaneNumbers'
>;

// A reservation request a customer sent from the public form. Times are
// ISO 8601 in UTC; call times are "HH:MM" in the venue's time zone.
export type BookingRequestRow = {
    id: number;
    name: string;
    phone: string;
    partySize: number;
    minutes: number;
    lanesNeeded: number;
    startsAt: string;
    // When the customer can be called; both null means any time.
    contactFrom: string | null;
    contactUntil: string | null;
    // True while now falls inside the times the customer gave.
    callNow: boolean;
    notes: string | null;
    // missed: nobody dealt with it before its time came.
    status: 'pending' | 'missed' | 'confirmed' | 'declined';
    submittedAt: string;
    handledAt: string | null;
    handledBy: string | null;
    declineReason: string | null;
};

// What the reservation form starts from when it confirms a request.
export type RequestToConfirm = Pick<
    BookingRequestRow,
    'id' | 'name' | 'phone' | 'partySize' | 'minutes' | 'startsAt' | 'notes'
>;

// A lane in the reservation form, and whether it is free for the chosen time.
export type LaneOption = {
    id: number;
    number: number;
    hasBumpers: boolean;
    available: boolean;
};

export type ReservationLimits = {
    maxPartySize: number;
    // How long before a reservation its lanes close to everyone.
    leadMinutes: number;
    maxDaysAhead: number;
    // The most reservations a search lists.
    searchLimit: number;
};

// What the lanes and the line are doing at this moment, counted as the lane
// board shows them. Times are ISO 8601 strings in UTC.
export type DashboardRightNow = {
    lanes: {
        total: number;
        free: number;
        inPlay: number;
        // Held for a called walk-in on its way, or a reservation that has
        // started without checking in.
        heldForParty: number;
        outOfOrder: number;
        // Closed for re-oiling or maintenance.
        maintenance: number;
        // Kept empty for a reservation about to start.
        keptClear: number;
    };
    // When the first of the sessions in play ends; null when nobody plays.
    nextSessionEndsAt: string | null;
    line: { waiting: number; called: number };
};

// What staff have to deal with now, as the dashboard lists it. Every time is
// an ISO 8601 string in UTC.
export type DashboardAttention = {
    // Reservations still to check in with a lane out of order, soonest first.
    reservationsToMove: {
        id: number;
        customerName: string;
        startsAt: string;
        closedLaneNumbers: number[];
    }[];
    // Reservations that have started with nobody checked in. Each becomes a
    // no-show at noShowAt.
    lateReservations: {
        id: number;
        customerName: string;
        startsAt: string;
        noShowAt: string;
        laneNumbers: number[];
    }[];
    // Reservation requests waiting, and how many of them it is a good time
    // to call about.
    requests: { waiting: number; callNow: number };
    // Lanes out of order. The date is only an estimate; overdue once it has
    // passed with the lane still closed.
    closedLanes: {
        number: number;
        reason: LaneClosureReason | null;
        until: string | null;
        overdue: boolean;
    }[];
};

// How the venue has done over the last so many days, today included. Days
// and hours are the venue's own, whatever the device's time zone.
export type DashboardAnalytics = {
    days: number;
    // The first and last day covered ("2026-10-05"), both included.
    firstDay: string;
    lastDay: string;
    // Parties that checked in, by how they came.
    sessions: { walkIns: number; reservations: number };
    people: number;
    // Time on the lanes, all sessions and lanes together.
    laneMinutes: number;
    noShows: number;
    // Reservations for those days that were cancelled.
    cancelled: number;
    // The groups that joined the line in those days.
    waitlist: {
        joined: number;
        joinedOnline: number;
        missedCalls: number;
        left: number;
        // From joining to being called; null when nobody was called.
        averageWaitMinutes: number | null;
    };
    // One row per day ("2026-10-05"), oldest first.
    byDay: { date: string; walkIns: number; reservations: number }[];
    // Sessions by the hour they started (0 to 23), from the earliest hour
    // with any to the latest. Empty when there were none.
    byHour: { hour: number; sessions: number }[];
    // The busiest times: each day of the week (0 is Sunday), Monday first,
    // with how many sessions started in each hour byHour lists, and how many
    // of that weekday the days hold. Empty when there were no sessions.
    byWeekday: { weekday: number; days: number; sessions: number[] }[];
    // How long each lane was out of use in those days, up to now, lowest
    // number first. Lanes never closed then are left out.
    closedTime: {
        number: number;
        repairMinutes: number;
        // Closed for a short job: re-oiling or maintenance.
        workMinutes: number;
    }[];
    // When the app began logging repairs; ones before it are not counted.
    repairsRecordedSince: string;
};
