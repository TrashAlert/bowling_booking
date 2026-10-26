<?php

use App\Models\ActivityLogEntry;
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

/**
 * A party with notifications on that joins the line behind a session with
 * $minutesLeft to go on the venue's only lane.
 */
function waitingBehindSession(int $minutesLeft, string $name = 'Farah'): WaitlistEntry
{
    test()->travelTo('2026-10-01 18:00:00');
    laneInPlay();
    test()->travel(60 - $minutesLeft)->minutes();

    return turnOnPush(joinWaitlist(name: $name));
}

test('a waiting party is told to get ready once its wait is down to 10 minutes', function () {
    $entry = waitingBehindSession(minutesLeft: 10);

    mock(WebPush::class)->shouldReceive('sendOneNotification')->once()
        ->withArgs(fn (SubscriptionInterface $subscription, string $payload, array $options) => $subscription->getEndpoint() === 'https://fcm.googleapis.com/fcm/send/abc'
            && json_decode($payload, true) === [
                'title' => 'Get ready, Farah',
                'body' => 'A lane should be ready for you in about 10 minutes. Start heading back to the counter so you are here when we call you.',
                'url' => "/waitlist/{$entry->token}",
            ]
            && $options === ['TTL' => 600, 'urgency' => 'high'])
        ->andReturn(pushAnswer(201));

    $told = app(WaitlistPush::class)->notifyGetReady();

    expect($told)->toBe(1)
        ->and($entry->refresh()->get_ready_sent_at?->toDateTimeString())->toBe('2026-10-01 18:50:00')
        ->and(ActivityLogEntry::query()->where('action', 'waitlist.get_ready')->sole()->summary)
        ->toBe("Told Farah's group (4 people) to get ready: a lane is expected in about 10 minutes.");
});

test('a party with a longer wait is not told to get ready yet', function () {
    $entry = waitingBehindSession(minutesLeft: 11);
    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    $told = app(WaitlistPush::class)->notifyGetReady();

    expect($told)->toBe(0)
        ->and($entry->refresh()->get_ready_sent_at)->toBeNull();
});

test('a party is told to get ready only once', function () {
    waitingBehindSession(minutesLeft: 10);
    mock(WebPush::class)->shouldReceive('sendOneNotification')->once()->andReturn(pushAnswer(201));
    app(WaitlistPush::class)->notifyGetReady();
    $this->travel(1)->minutes();

    $told = app(WaitlistPush::class)->notifyGetReady();

    expect($told)->toBe(0);
});

test('a party that never turned notifications on is not told to get ready', function () {
    $this->travelTo('2026-10-01 18:00:00');
    laneInPlay();
    $this->travel(55)->minutes();
    $entry = joinWaitlist();
    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    $told = app(WaitlistPush::class)->notifyGetReady();

    expect($told)->toBe(0)
        ->and($entry->refresh()->get_ready_sent_at)->toBeNull();
});

test('a party that has been called is not told to get ready', function () {
    Lane::factory()->create();
    turnOnPush(joinWaitlist());
    app(WaitlistService::class)->callNextParties();
    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    $told = app(WaitlistPush::class)->notifyGetReady();

    expect($told)->toBe(0);
});

test('no party is told to get ready while the key pair is not set up', function () {
    config(['services.web_push.private_key' => null]);
    $entry = waitingBehindSession(minutesLeft: 5);
    mock(WebPush::class)->shouldNotReceive('sendOneNotification');

    $told = app(WaitlistPush::class)->notifyGetReady();

    // Not marked, so it is still told once notifications are set up.
    expect($told)->toBe(0)
        ->and($entry->refresh()->get_ready_sent_at)->toBeNull();
});
