<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's StartSession, except that a signed-out (guest) response does not
 * send back the session cookie the browser already sent with the request.
 *
 * Signing in moves the user to a new session id. Any request the browser
 * already had in flight with the old, guest cookie (another tab's
 * notifications poll, a tab that is reloading) used to answer after the
 * login with "Set-Cookie: <old guest id>", replacing the new signed-in
 * cookie, so the next page load landed on the login page again and the user
 * had to sign in twice. Repeating an unchanged guest cookie adds nothing, so
 * it is dropped; new, regenerated and signed-in session cookies (which renew
 * the cookie's lifetime) are still sent as before.
 */
class StartSession extends BaseStartSession
{
    /**
     * @param  Session  $session
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next): mixed
    {
        $incomingSessionId = $request->cookies->get($session->getName());

        $response = parent::handleStatefulRequest($request, $session, $next);

        if (
            $response instanceof Response
            && is_string($incomingSessionId)
            && $incomingSessionId === $session->getId()
            && ! $this->holdsSignedInUser($session)
        ) {
            $config = $this->manager->getSessionConfig();

            $response->headers->removeCookie($session->getName(), $config['path'], $config['domain']);
        }

        return $response;
    }

    /** Whether any auth guard has a user signed in to this session ("login_{guard}_{hash}"). */
    protected function holdsSignedInUser(Session $session): bool
    {
        foreach (array_keys($session->all()) as $key) {
            if (Str::startsWith((string) $key, 'login_')) {
                return true;
            }
        }

        return false;
    }
}
