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
    // A rough guess of the minutes left to wait; null once called or out of
    // the line, or when no lane can be found.
    estimatedWaitMinutes: number | null;
    // The lanes held for the party once called, then the lanes it plays on.
    laneNumbers: number[];
    // When a called party must have checked in by.
    checkInBy: string | null;
    sessionEndsAt: string | null;
    // Whether a phone will be sent a notification when the party is called.
    pushOn: boolean;
    // Null for a walk-in added by staff, who pays no deposit.
    deposit: {
        amountCents: number;
        outcome: DepositOutcome | null;
    } | null;
};

// How the deposit is being taken.
export type DepositPayment = {
    // True while no real payment provider is connected: Pay takes no money.
    standIn: boolean;
    // True once the party has been sent to the provider's page to pay and
    // the provider has not yet told us it did.
    awaiting: boolean;
};

// What a party is about to pay a deposit for.
export type PendingDeposit = {
    name: string;
    partySize: number;
    minutes: number;
    amountCents: number;
};
