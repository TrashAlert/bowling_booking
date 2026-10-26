<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

/**
 * The page's policy with its one-off code taken out, and that code.
 *
 * @return array{0: string, 1: string}
 */
function policyAndNonce(string $policy): array
{
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match);

    return [str_replace($match[1], '{nonce}', $policy), $match[1]];
}

test('pages tell browsers not to frame them, read files as another type or pass on their address', function () {
    $response = $this->get(route('home'));

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin');
});

test('only the app\'s own scripts, and inline scripts carrying the page\'s code, may run', function () {
    $response = $this->get(route('home'));

    [$policy, $nonce] = policyAndNonce($response->headers->get('Content-Security-Policy'));
    expect($policy)->toBe("default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'")
        ->and($response->getContent())->toContain("<script nonce=\"{$nonce}\">");
});

test('each response gets its own code', function () {
    $first = $this->get(route('home'))->headers->get('Content-Security-Policy');

    $second = $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect(policyAndNonce($first)[1])->not->toBe(policyAndNonce($second)[1]);
});

test('error pages carry the headers too', function () {
    $response = $this->get('/no-such-page');

    $response->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

test('a response that sets its own policy keeps it', function () {
    Route::get('/test-own-policy', fn () => response('A file.')->header('Content-Security-Policy', "default-src 'none'; sandbox"));

    $response = $this->get('/test-own-policy');

    $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
});

test('Laravel\'s debug page for a fault gets no policy, so it still shows', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/test-fault', fn () => throw new RuntimeException('Broken'));

    $response = $this->get('/test-fault');

    $response->assertInternalServerError()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Frame-Options', 'DENY');
});

test('while the Vite dev server runs, pages may load from it and talk to it', function () {
    $hotFile = storage_path('framework/testing/hot');
    file_put_contents($hotFile, 'http://[::1]:5173');
    Vite::useHotFile($hotFile);

    try {
        $response = $this->get(route('home'));
    } finally {
        unlink($hotFile);
    }

    [$policy] = policyAndNonce($response->headers->get('Content-Security-Policy'));
    expect($policy)->toContain("script-src 'self' 'nonce-{nonce}' http: ws:")
        ->and($policy)->toContain("connect-src 'self' http: ws:");
});

test('cookies set over HTTPS are only sent back over HTTPS, under a name that is not the framework\'s', function () {
    $response = $this->get('https://localhost/');

    expect($response->getCookie('bowling_session', false)?->isSecure())->toBeTrue()
        ->and($response->getCookie('XSRF-TOKEN', false)?->isSecure())->toBeTrue()
        ->and($response->getCookie('laravel-session', false))->toBeNull();
});

test('cookies set over plain HTTP, as on the venue network, can still be sent back', function () {
    $response = $this->get('http://localhost/');

    expect($response->getCookie('bowling_session', false)?->isSecure())->toBeFalse()
        ->and($response->getCookie('XSRF-TOKEN', false)?->isSecure())->toBeFalse();
});
