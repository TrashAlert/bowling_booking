<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Models\WaitlistEntry;
use Illuminate\Support\Arr;
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
        $entry->update(['push_subscription' => [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $publicKey, 'auth' => $authToken],
        ]]);
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

            try {
                $report = app(WebPush::class)->sendOneNotification(
                    Subscription::create([...$entry->push_subscription, 'contentEncoding' => 'aes128gcm']),
                    json_encode($this->message($entry)),
                    // No use arriving after the party's time to check in is up.
                    ['TTL' => config('bowling.waitlist_checkin_minutes') * 60, 'urgency' => 'high'],
                );

                if ($report->isSubscriptionExpired()) {
                    $entry->update(['push_subscription' => null]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * What the notification says, and the page it opens when tapped.
     *
     * @return array{title: string, body: string, url: string}
     */
    private function message(WaitlistEntry $entry): array
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
