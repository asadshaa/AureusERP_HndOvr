<?php

namespace Webkul\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Security\Services\CurrentCompany;

/**
 * Every signed-in request runs inside one of the user's own companies: if
 * the active company is not (or no longer) one of them, the first one is
 * used; a user who belongs to no active company is refused.
 */
class EnsureActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = CurrentCompany::user();

        if ($user && ! CurrentCompany::ensureValid($user)) {
            abort(403, 'Your account is not linked to any active company. Ask your administrator.');
        }

        return $next($request);
    }
}
