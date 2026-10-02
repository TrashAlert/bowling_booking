<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;

/**
 * Read-only data for a party's own page about its place in the waitlist.
 * Every time is an ISO 8601 string in UTC; the browser converts it for
 * display.
 */
class WaitlistTicket
{
    /**
     * What one party sees about itself. Nothing about any other party is
     * included, and neither is any secret token.
     *
     * @return array{
     *     status: string,
     *     customerName: string,
     *     partySize: int,
     *     minutes: int,
     *     joinedAt: string,
     *     position: int|null,
     *     partiesAhead: int|null,
     *     laneNumbers: list<int>,
     *     checkInBy: string|null,
     *     sessionEndsAt: string|null,
     *     deposit: array{amountCents: int, outcome: string|null}|null,
     * }
     */
    public function for(WaitlistEntry $entry): array
    {
        $entry->loadMissing(['customer', 'booking.allocations.lane', 'deposit']);
        $entry->deposit?->setRelation('entry', $entry);

        $position = $entry->position();
        $allocations = $entry->booking?->allocations ?? collect();

        // While called the lanes are held for the party; once seated they are its session.
        $lanes = match ($entry->status) {
            WaitlistStatus::Called => $allocations->where('status', AllocationStatus::Held),
            WaitlistStatus::Seated => $allocations->where('status', AllocationStatus::Active),
            default => collect(),
        };

        return [
            'status' => $entry->status->value,
            'customerName' => $entry->customer->name,
            'partySize' => $entry->party_size,
            'minutes' => $entry->minutes,
            'joinedAt' => $entry->created_at->toIso8601String(),
            'position' => $position,
            'partiesAhead' => $position === null ? null : $position - 1,
            'laneNumbers' => $lanes->pluck('lane.number')->sort()->values()->all(),
            'checkInBy' => $entry->status === WaitlistStatus::Called
                ? $lanes->min('held_until')?->toIso8601String()
                : null,
            'sessionEndsAt' => $entry->status === WaitlistStatus::Seated
                ? $lanes->max('ends_at')?->toIso8601String()
                : null,
            'deposit' => $entry->deposit === null ? null : [
                'amountCents' => $entry->deposit->amount_cents,
                'outcome' => $entry->deposit->outcome()?->value,
            ],
        ];
    }
}
