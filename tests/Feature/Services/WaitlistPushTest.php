<?php

use App\Models\Lane;
use App\Models\WaitlistEntry;
use App\Services\WaitlistPush;
use App\Services\WaitlistService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Exceptions;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;

use function Pest\Laravel\mock;

beforeEach(function () {
    config([
        'services.web_push.public_key' => 'public-key',
        'services.web_push.private_key' => 'private-key',
    ]);
});

/**
 * Turn notifications on for a party, as its phone's browser would.
 */
function turnOnPush(WaitlistEntry $entry, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): WaitlistEntry
{
    app(WaitlistPush::class)->subscribe($entry, $endpoint, 'p256dh-key', 'auth-token');

    return $entry;
}

/**
 * What a push service answers with the given status.
 */
function pushAnswer(int $status): MessageSentReport
{
    return new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/abc'), new Response($status), $status < 300);
}

test('a called party with notifications on is told to come to the counter', function () {
    Lane::factory()->create(['number' => 4]);
    $entry = turnOnPush(joinWaitlist(name: 'Farah'));
    $called = app(WaitlistService::class)->callNextParties();

    mock(WebPush::class)->shouldReceive('sendOneNotification')->once()
        ->withArgs(fn (SubscriptionInterface $subscription, string $payload, array $options) => $subscription->getEndpoint() === 'https://fcm.googleapis.com/fcm/send/abc'
            && $subscription->getPublicKey() === 'p256dh-key'
            && $subscription->getAuthToken() === 'auth-token'
            && json_decode($payload, true) === [
                'title' => 'It is your turn, Farah',
                'body' => 'Go to the counter now. Lane 4 is held for you for 5 minutes.',
                'url' => "/waitlist/{$entry->token}",
            ]
            && $options === ['TTL' => 300, 'urgency' => 'high'])
        ->andReturn(pushAnswer(201));

    app(WaitlistPush::class)->notifyCalled($called);
});

test('a party that never turned notifications on is not notified', function () {
    Lane::factory()->create();
    joinWaitlist();
    $called = app(WaitlistService::class)->callNextParties();

    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    app(WaitlistPush::class)->notifyCalled($called);
});

test('nothing is sent while the key pair is not set up', function () {
    config(['services.web_push.private_key' => null]);
    Lane::factory()->create();
    turnOnPush(joinWaitlist());
    $called = app(WaitlistService::class)->callNextParties();

    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    app(WaitlistPush::class)->notifyCalled($called);
});

test('a phone that has dropped its subscription is forgotten', function () {
    Lane::factory()->create();
    $entry = turnOnPush(joinWaitlist());
    $called = app(WaitlistService::class)->callNextParties();
    mock(WebPush::class)->shouldReceive('sendOneNotification')->once()->andReturn(pushAnswer(410));

    app(WaitlistPush::class)->notifyCalled($called);

    expect($entry->refresh()->push_subscription)->toBeNull();
});

test('a notification that fails is reported and the others are still sent', function () {
    Exceptions::fake();
    Lane::factory()->count(2)->create();
    turnOnPush(joinWaitlist(name: 'First'), 'https://fcm.googleapis.com/fcm/send/first');
    $second = turnOnPush(joinWaitlist(name: 'Second'), 'https://fcm.googleapis.com/fcm/send/second');
    $called = app(WaitlistService::class)->callNextParties();
    mock(WebPush::class)->shouldReceive('sendOneNotification')->twice()
        ->andReturnUsing(fn (SubscriptionInterface $subscription) => str_ends_with($subscription->getEndpoint(), '/first')
            ? throw new RuntimeException('The push service is down.')
            : pushAnswer(201));

    app(WaitlistPush::class)->notifyCalled($called);

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The push service is down.');
    expect($second->refresh()->push_subscription)->not->toBeNull();
});
