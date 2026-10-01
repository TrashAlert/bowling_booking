export type LaneStatus = 'open' | 'out_of_order';

export type LaneCardState =
    | 'free'
    | 'in_play'
    | 'held'
    | 'reserved'
    | 'closed_for_reservation'
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

// Every time on the board is an ISO 8601 string in UTC.
export type LaneCard = {
    id: number;
    number: number;
    hasBumpers: boolean;
    isOpen: boolean;
    state: LaneCardState;
    current: {
        bookingId: number | null;
        customerName: string | null;
        partySize: number | null;
        startsAt: string;
        endsAt: string;
        heldUntil: string | null;
        note: string | null;
        // True while a checked-in party is still playing on this lane.
        canExtend: boolean;
        // How much longer it could play before a lane of its is booked again;
        // null when nothing is booked after it.
        extendableMinutes: number | null;
    } | null;
    next: {
        startsAt: string;
        customerName: string | null;
        note: string | null;
        // True when the lane closes then, ahead of a reservation, not for play.
        isClosure: boolean;
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
    laneNumbers: number[];
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
    lanes: { id: number; number: number }[];
    notes: string | null;
};

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
