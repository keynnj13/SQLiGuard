<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `role:<role>[,<role>...]`, e.g. `role:administrator`.
 *
 * Runs after `auth`, so the user is always present; a user without one of the
 * listed roles receives 403. Parameters are parsed with Role::from(), so a
 * misspelled role in a route definition fails loudly instead of denying silently.
 */
class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
