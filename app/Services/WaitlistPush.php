<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Push notifications to the phone of a party in the waitlist, so it hears it
 * has been called even with the phone locked. A party only gets them after
 * turning them on from its own page, which gives us its phone's push address.
 */
class WaitlistPush
{
    public function __construct(
        private ActivityLog $log,
        private WaitlistEstimate $estimate,
    ) {}

    /**
     * Whether notifications can be sent at all: they need the key pair made
     * with "php artisan push:keys".
     */
    public function isConfigured(): bool
    {
        return filled(config('services.web_push.public_key'))
            && filled(config('services.web_push.private_key'));
    }

    /**
     * Remember where to notify a party: the address and keys its phone's
     * browser handed over. A later one replaces the first.
     */
    public function subscribe(WaitlistEntry $entry, string $endpoint, string $publicKey, string $authToken): void
    {
        DB::transaction(function () use ($entry, $endpoint, $publicKey, $authToken) {
            $entry->update(['push_subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => $publicKey, 'auth' => $authToken],
            ]]);

            $this->log->record('waitlist.notifications_on', "{$this->log->party($entry->customer->name, $entry->party_size)} turned on notifications.", $entry, customerName: $entry->customer->name);
        });
    }

    /**
     * Tell each party that has just been called to come to the counter.
     * Parties that never turned notifications on are passed over.
     *
     * A notification that can't be sent never stops a party from being
     * called: the failure is reported and the rest still go out. A phone
     * that has since dropped its subscription is forgotten.
     *
     * @param  iterable<WaitlistEntry>  $entries
     */
    public function notifyCalled(iterable $entries): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry->push_subscription === null) {
                continue;
            }

            // No use arriving after the party's time to check in is up.
            $this->send($entry, $this->calledMessage($entry), config('bowling.waitlist_checkin_minutes') * 60);
        }
    }

    /**
     * Tell each waiting party whose turn is near to start heading back to the
     * counter: those with notifications on whose estimated wait is
     * bowling.get_ready_minutes or less. Each party is told once, even if its
     * wait grows again afterwards. Returns how many were told.
     *
     * The wait is only an estimate, so this is a heads-up, not a call: the
     * party's time to check in still starts when it is called.
     */
    public function notifyGetReady(): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $limit = config('bowling.get_ready_minutes');

        $waits = array_filter(
            $this->estimate->forWaitingParties(),
            fn (?int $minutes) => $minutes !== null && $minutes <= $limit,
        );

        if ($waits === []) {
            return 0;
        }

        $due = WaitlistEntry::query()
            ->whereKey(array_keys($waits))
            ->where('status', WaitlistStatus::Waiting->value)
            ->whereNull('get_ready_sent_at')
            ->whereNotNull('push_subscription')
            ->with('customer')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($due as $entry) {
            $minutes = $waits[$entry->id];

            // Marked first, so a phone that can't be reached isn't tried
            // again every minute.
            DB::transaction(function () use ($entry, $minutes) {
                $entry->update(['get_ready_sent_at' => now()]);

                $this->log->record('waitlist.get_ready', sprintf(
                    'Told %s to get ready: a lane is expected %s.',
                    $this->log->party($entry->customer->name, $entry->party_size),
                    $minutes === 0 ? 'any moment' : "in about {$this->log->length($minutes)}",
                ), $entry, customerName: $entry->customer->name);
            });

            // No use arriving once the party is likely to have been called.
            $this->send($entry, $this->getReadyMessage($entry, $minutes), max($minutes, 1) * 60);
        }

        return $due->count();
    }

    /**
     * Send one notification to a party's phone. A failure is reported and
     * never stops the caller; a phone that has dropped its subscription is
     * forgotten.
     *
     * @param  array{title: string, body: string, url: string}  $message
     */
    private function send(WaitlistEntry $entry, array $message, int $ttlSeconds): void
    {
        try {
            $report = app(WebPush::class)->sendOneNotification(
                Subscription::create([...$entry->push_subscription, 'contentEncoding' => 'aes128gcm']),
                json_encode($message),
                ['TTL' => $ttlSeconds, 'urgency' => 'high'],
            );

            if ($report->isSubscriptionExpired()) {
                $entry->update(['push_subscription' => null]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What the notification to get ready says, and the page it opens.
     *
     * @return array{title: string, body: string, url: string}
     */
    private function getReadyMessage(WaitlistEntry $entry, int $minutes): array
    {
        return [
            'title' => __('Get ready, :name', ['name' => $entry->customer->name]),
            'body' => $minutes <= 1
                ? __('A lane should be ready for you any moment. Head back to the counter now so you are here when we call you.')
                : __('A lane should be ready for you in about :minutes minutes. Start heading back to the counter so you are here when we call you.', ['minutes' => $minutes]),
            'url' => route('waitlist.show', ['entry' => $entry->token], absolute: false),
        ];
    }

    /**
     * What the notification of a call says, and the page it opens when tapped.
     *
     * @return array{title: string, body: string, url: string}
     */
    private function calledMessage(WaitlistEntry $entry): array
    {
        $entry->loadMissing(['customer', 'booking.allocations.lane']);

        $lanes = $entry->booking?->allocations
            ->where('status', AllocationStatus::Held)
            ->pluck('lane.number')
            ->sort()
            ->all() ?? [];

        return [
            'title' => __('It is your turn, :name', ['name' => $entry->customer->name]),
            'body' => trans_choice(
                '{0} Go to the counter now. You have :minutes minutes to check in.|{1} Go to the counter now. Lane :lanes is held for you for :minutes minutes.|[2,*] Go to the counter now. Lanes :lanes are held for you for :minutes minutes.',
                count($lanes),
                ['lanes' => Arr::join($lanes, ', ', ' and '), 'minutes' => config('bowling.waitlist_checkin_minutes')],
            ),
            'url' => route('waitlist.show', ['entry' => $entry->token], absolute: false),
        ];
    }
}
