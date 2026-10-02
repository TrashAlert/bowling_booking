<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * What a phone's browser sends when notifications are turned on.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pushSubscription(array $overrides = []): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'expirationTime' => null,
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
        ...$overrides,
    ];
}

test('a party can turn notifications on for its phone', function () {
    $entry = joinWaitlist();

    $response = $this->put(route('waitlist.push.store', ['entry' => $entry->token]), pushSubscription());

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($entry->refresh()->push_subscription)->toBe([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
    ]);
});

test('the party page says whether its phone will be notified, without giving the address away', function () {
    $entry = joinWaitlist();
    $before = $this->get(route('waitlist.show', ['entry' => $entry->token]));
    $this->put(route('waitlist.push.store', ['entry' => $entry->token]), pushSubscription());

    $after = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $before->assertInertia(fn (Assert $page) => $page->where('ticket.pushOn', false));
    $after->assertInertia(fn (Assert $page) => $page->where('ticket.pushOn', true));
    expect(json_encode($after->inertiaProps()))
        ->not->toContain('fcm.googleapis.com')
        ->not->toContain('auth-token');
});

test('the public key is only handed out once notifications are set up', function () {
    $entry = joinWaitlist();
    $notSetUp = $this->get(route('waitlist.show', ['entry' => $entry->token]));
    config(['services.web_push.public_key' => 'public-key', 'services.web_push.private_key' => 'private-key']);

    $setUp = $this->get(route('waitlist.show', ['entry' => $entry->token]));

    $notSetUp->assertInertia(fn (Assert $page) => $page->where('pushKey', null));
    $setUp->assertInertia(fn (Assert $page) => $page->where('pushKey', 'public-key'));
    expect(json_encode($setUp->inertiaProps()))->not->toContain('private-key');
});

test('notifications can only be sent to a known push service', function (string $endpoint, string $message) {
    $entry = joinWaitlist();

    $response = $this->put(route('waitlist.push.store', ['entry' => $entry->token]), pushSubscription(['endpoint' => $endpoint]));

    $response->assertSessionHasErrors(['endpoint' => $message]);
    expect($entry->refresh()->push_subscription)->toBeNull();
})->with([
    'another site' => ['https://evil.example.com/push', 'We cannot send notifications to this browser.'],
    'a look-alike of a push service' => ['https://fcm.googleapis.com.evil.example/push', 'We cannot send notifications to this browser.'],
    'a push service name inside the path' => ['https://evil.example.com/fcm.googleapis.com', 'We cannot send notifications to this browser.'],
    'this server itself' => ['https://127.0.0.1/staff/board', 'We cannot send notifications to this browser.'],
    'not a secure address' => ['http://fcm.googleapis.com/fcm/send/abc123', 'The endpoint field must be a valid URL.'],
]);

test('the keys to encrypt notifications with are required', function () {
    $entry = joinWaitlist();

    $response = $this->put(route('waitlist.push.store', ['entry' => $entry->token]), pushSubscription(['keys' => []]));

    $response->assertSessionHasErrors(['keys.p256dh', 'keys.auth']);
    expect($entry->refresh()->push_subscription)->toBeNull();
});

test('turning notifications on with a link that matches no party returns 404', function () {
    $response = $this->put(route('waitlist.push.store', ['entry' => str_repeat('x', 40)]), pushSubscription());

    $response->assertNotFound();
});
