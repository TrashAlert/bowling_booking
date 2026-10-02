<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;

afterEach(function () {
    TrustProxies::flushState();
});

/**
 * Join the waitlist as the proxy passing a customer on: the request comes
 * from the proxy's own address and names the customer in a header.
 */
function joinThroughProxy(string $customerAddress): TestResponse
{
    return test()->withHeaders(['X-Forwarded-For' => $customerAddress])->post(route('waitlist.store'), [
        'name' => 'Farah Aziz',
        'phone' => '0123456789',
        'party_size' => 4,
        'minutes' => 60,
    ]);
}

test('customers behind a trusted proxy each get their own limit on joining the waitlist', function () {
    config(['app.trusted_proxies' => '10.9.9.9, 127.0.0.1']);
    (new AppServiceProvider($this->app))->boot();
    foreach (range(1, 10) as $try) {
        joinThroughProxy('203.0.113.7');
    }

    $sameCustomer = joinThroughProxy('203.0.113.7');
    $anotherCustomer = joinThroughProxy('198.51.100.20');

    $sameCustomer->assertTooManyRequests();
    $anotherCustomer->assertRedirect();
});

test('a visitor cannot dodge the limit by naming another address when no proxy is trusted', function () {
    foreach (range(1, 10) as $try) {
        joinThroughProxy('203.0.113.7');
    }

    $response = joinThroughProxy('198.51.100.20');

    $response->assertTooManyRequests();
});

test('a trusted proxy is believed when it says the customer is on https', function () {
    config(['app.trusted_proxies' => '127.0.0.1']);
    (new AppServiceProvider($this->app))->boot();

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'bowl.example.com'])
        ->post('/waitlist/join', ['name' => 'Farah Aziz', 'phone' => '0123456789', 'party_size' => 4, 'minutes' => 60]);

    expect($response->headers->get('Location'))->toStartWith('https://bowl.example.com/waitlist/deposit/');
});
