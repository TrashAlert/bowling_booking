<?php

namespace App\Services;

use App\Enums\BookingRequestStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NoLaneAvailableException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservation requests from customers. A customer asks for a time and says
 * when they can be called; staff call them, then confirm the request as a
 * reservation or decline it. A request holds no lanes.
 */
class BookingRequests
{
    // The most handled requests listed for looking back.
    private const HANDLED_LIMIT = 50;

    public function __construct(private BookingService $bookings) {}

    /**
     * Save a customer's request. $contactFrom and $contactUntil are "HH:MM"
     * in the venue's time zone; both null means they can be called any time.
     */
    public function submit(
        string $name,
        string $phone,
        int $partySize,
        int $minutes,
        CarbonImmutable $startsAt,
        ?string $contactFrom,
        ?string $contactUntil,
        ?string $notes,
    ): BookingRequest {
        return BookingRequest::create([
            'name' => $name,
            'phone' => $phone,
            'party_size' => $partySize,
            'minutes' => $minutes,
            'starts_at' => $startsAt->utc(),
            'contact_from' => $contactFrom,
            'contact_until' => $contactUntil,
            'notes' => $notes,
            'status' => BookingRequestStatus::Pending,
        ]);
    }

    /**
     * Make the reservation staff agreed with the customer, and mark the
     * request as confirmed by it. The details may differ from what was asked
     * for. If a lane isn't free, nothing changes and the request is still
     * waiting.
     *
     * @param  Collection<int, Lane>  $lanes
     *
     * @throws InvalidStateException if the request has already been dealt with.
     * @throws NoLaneAvailableException naming the first lane that isn't free.
     */
    public function confirm(
        BookingRequest $request,
        string $name,
        string $phone,
        Collection $lanes,
        int $minutes,
        int $partySize,
        CarbonImmutable $startsAt,
        ?string $notes,
        User $by,
    ): Booking {
        return DB::transaction(function () use ($request, $name, $phone, $lanes, $minutes, $partySize, $startsAt, $notes, $by) {
            $request = $this->lockPending($request);

            $booking = $this->bookings->reserveForNewCustomer($name, $phone, $lanes, $minutes, $partySize, $startsAt, $notes);

            $request->update([
                'status' => BookingRequestStatus::Confirmed,
                'booking_id' => $booking->id,
                'handled_by' => $by->id,
                'handled_at' => now(),
            ]);

            return $booking;
        });
    }

    /**
     * Turn a request down, with a reason kept for staff.
     *
     * @throws InvalidStateException if the request has already been dealt with.
     */
    public function decline(BookingRequest $request, ?string $reason, User $by): BookingRequest
    {
        return DB::transaction(function () use ($request, $reason, $by) {
            $request = $this->lockPending($request);

            $request->update([
                'status' => BookingRequestStatus::Declined,
                'decline_reason' => $reason,
                'handled_by' => $by->id,
                'handled_at' => now(),
            ]);

            return $request;
        });
    }

    /**
     * How many requests are waiting to be dealt with.
     */
    public function waitingCount(): int
    {
        return BookingRequest::query()->waiting()->count();
    }

    /**
     * Requests still to deal with, soonest requested time first.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(): array
    {
        return BookingRequest::query()
            ->waiting()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (BookingRequest $request) => $this->row($request))
            ->all();
    }

    /**
     * Requests already dealt with, or missed, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function handled(): array
    {
        return BookingRequest::query()
            ->where(fn ($query) => $query->where('status', '!=', BookingRequestStatus::Pending->value)->orWhere('starts_at', '<=', now()))
            ->with('handler')
            ->orderByRaw('coalesce(handled_at, starts_at) desc')
            ->orderByDesc('id')
            ->limit(self::HANDLED_LIMIT)
            ->get()
            ->map(fn (BookingRequest $request) => $this->row($request))
            ->all();
    }

    /**
     * One request as the staff Requests page lists it. Times are ISO 8601 in
     * UTC; call times are "HH:MM" in the venue's time zone.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     phone: string,
     *     partySize: int,
     *     minutes: int,
     *     lanesNeeded: int,
     *     startsAt: string,
     *     contactFrom: string|null,
     *     contactUntil: string|null,
     *     callNow: bool,
     *     notes: string|null,
     *     status: string,
     *     submittedAt: string,
     *     handledAt: string|null,
     *     handledBy: string|null,
     *     declineReason: string|null,
     * }
     */
    private function row(BookingRequest $request): array
    {
        $missed = $request->isPending() && $request->starts_at <= now();

        return [
            'id' => $request->id,
            'name' => $request->name,
            'phone' => $request->phone,
            'partySize' => $request->party_size,
            'minutes' => $request->minutes,
            'lanesNeeded' => $this->bookings->lanesNeeded($request->party_size),
            'startsAt' => $request->starts_at->toIso8601String(),
            'contactFrom' => $this->clock($request->contact_from),
            'contactUntil' => $this->clock($request->contact_until),
            'callNow' => $request->isPending() && ! $missed && $this->isGoodTimeToCall($request),
            'notes' => $request->notes,
            'status' => $missed ? 'missed' : $request->status->value,
            'submittedAt' => $request->created_at->toIso8601String(),
            'handledAt' => $request->handled_at?->toIso8601String(),
            'handledBy' => $request->handler?->name,
            'declineReason' => $request->decline_reason,
        ];
    }

    /**
     * Whether now falls inside the times the customer said they can be
     * called. A customer who can be called any time isn't flagged.
     */
    private function isGoodTimeToCall(BookingRequest $request): bool
    {
        if ($request->contact_from === null || $request->contact_until === null) {
            return false;
        }

        $now = now()->setTimezone(config('bowling.timezone'))->format('H:i');

        return $this->clock($request->contact_from) <= $now && $now < $this->clock($request->contact_until);
    }

    /**
     * Lock a request that is still waiting, or say it has been dealt with.
     *
     * @throws InvalidStateException
     */
    private function lockPending(BookingRequest $request): BookingRequest
    {
        $request = BookingRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! $request->isPending()) {
            throw new InvalidStateException(__('This request has already been dealt with.'));
        }

        return $request;
    }

    /**
     * A time from the database ("14:00:00") as "14:00".
     */
    private function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
