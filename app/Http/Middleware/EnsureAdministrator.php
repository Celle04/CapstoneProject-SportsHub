<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only an Administrator through.
 *
 * Applied ahead of `password.confirm` on the high-impact routes so a student
 * still receives a plain 403 instead of being redirected to the password
 * confirmation screen.
 */
class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === 'Administrator', 403);

        return $next($request);
    }
}
