<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells browsers to hold every response to stricter rules: no other site may
 * show the pages inside a frame, a file is never read as a different type
 * than it says it is, page addresses (which can hold a customer's secret
 * link) are never sent to other sites, and only the app's own scripts may
 * run. PHP's header naming its exact version is taken off, and cookies set
 * over HTTPS are marked HTTPS-only.
 */
class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A one-off code for this response. The page's own inline scripts
        // carry it, and an inline script without it is refused.
        Vite::useCspNonce();

        // Cookies set over HTTPS are only ever sent back over HTTPS. Staff on
        // the venue's plain-HTTP network still get cookies they can use. The
        // proxy in front is trusted by now, so a customer coming through the
        // public gate counts as HTTPS.
        if ($request->isSecure()) {
            config(['session.secure' => true]);
        }

        $response = $next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];

        if ($this->wantsPolicy($response)) {
            $headers['Content-Security-Policy'] = $this->policy((string) Vite::cspNonce());
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * What the page may load and run. Styles written inline stay allowed:
     * React sets some, and the brand colour is a style tag.
     */
    private function policy(string $nonce): string
    {
        // While the Vite dev server runs, pages load from it over http and
        // keep a socket open to it. Its address may be an IPv6 one, which a
        // policy can't name, so any http and ws address is allowed then.
        $devServer = Vite::isRunningHot() ? ' http: ws:' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$devServer}",
            "style-src 'self' 'unsafe-inline'{$devServer}",
            "img-src 'self' data:{$devServer}",
            "font-src 'self' data:{$devServer}",
            "connect-src 'self'{$devServer}",
            "worker-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Whether to send the policy. A response that set its own, such as a
     * stored file Laravel serves, keeps it. Laravel's debug page for a fault
     * gets none, since its inline scripts carry no code and it would show
     * blank; it only appears while APP_DEBUG is on.
     */
    private function wantsPolicy(Response $response): bool
    {
        if ($response->headers->has('Content-Security-Policy')) {
            return false;
        }

        $isDebugPage = config('app.debug')
            && $response->getStatusCode() >= 500
            && ($response->exception ?? null) !== null;

        return ! $isDebugPage;
    }
}
