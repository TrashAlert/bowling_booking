<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks search engines never to list a page or follow its links: for the
 * customer pages reached by a secret link, such as a party's place in line.
 * If such a link were ever posted somewhere public, it still wouldn't turn
 * up in search results.
 *
 * These pages are deliberately not blocked in robots.txt: a search engine
 * that may not fetch a page never sees this header, and can still list the
 * bare address.
 */
class KeepOutOfSearchEngines
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
