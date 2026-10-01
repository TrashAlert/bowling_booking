export type LaneStatus = 'open' | 'out_of_order';

export type LaneCardState =
    | 'free'
    | 'in_play'
    | 'held'
    | 'reserved'
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
    } | null;
    next: {
        startsAt: string;
        customerName: string | null;
        note: string | null;
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
