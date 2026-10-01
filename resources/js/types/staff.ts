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
};
