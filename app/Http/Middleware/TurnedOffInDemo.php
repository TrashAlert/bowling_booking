<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the public demo, refuse an action that would spoil the demo for other
 * visitors, such as changing the shared logins' passwords or removing them,
 * and say why. Everywhere else the action goes ahead as usual.
 */
class TurnedOffInDemo
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('bowling.demo')) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('This is turned off in the demo, so every visitor can keep using the same logins.'),
        ]);

        return back();
    }
}
