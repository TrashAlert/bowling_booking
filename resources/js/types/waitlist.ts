import type { WaitlistStatus } from './staff';

// What became of the deposit paid to join the waitlist online.
export type DepositOutcome = 'held' | 'applied' | 'refund_due' | 'forfeited';

// What a party sees about its own place in the waitlist. Every time is an
// ISO 8601 string in UTC.
export type WaitlistTicket = {
    status: WaitlistStatus;
    customerName: string;
    partySize: number;
    minutes: number;
    joinedAt: string;
    // 1 means next in line. Null once the party is no longer in line.
    position: number | null;
    partiesAhead: number | null;
    // The lanes held for the party once called, then the lanes it plays on.
    laneNumbers: number[];
    // When a called party must have checked in by.
    checkInBy: string | null;
    sessionEndsAt: string | null;
    // Null for a walk-in added by staff, who pays no deposit.
    deposit: {
        amountCents: number;
        outcome: DepositOutcome | null;
    } | null;
};

// What a party is about to pay a deposit for.
export type PendingDeposit = {
    name: string;
    partySize: number;
    minutes: number;
    amountCents: number;
};
