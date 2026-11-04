<?php

/**
 * The admin login as a browser does it: real cookies carried between
 * requests, the session read back from the database on every request
 * (SESSION_DRIVER=database, SESSION_ENCRYPT=true, like .env), the login
 * Livewire call POSTed to the update endpoint, and the requests other tabs
 * have in flight while the user signs in.
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

const LOGIN_FLOW_HOST = 'http://127.0.0.1:8080';
const LOGIN_FLOW_PASSWORD = 'login-flow-secret-123';

beforeEach(function () {
    config([
        'app.url'          => 'http://127.0.0.1:8000',
        'session.driver'   => 'database',
        'session.encrypt'  => true,
        'session.cookie'   => 'aureuserp_session',
        'session.lifetime' => 120,
    ]);

    $this->jar = [];
});

/**
 * One browser request: sends the given cookie jar (default: the browser's),
 * applies every Set-Cookie of the response to the browser's jar, and drops
 * the in-memory session and guards first so all state comes from the DB.
 *
 * @param  array<string, string>|null  $cookies
 * @param  array<string, string>  $server
 */
function browserRequest($test, string $method, string $path, ?array $cookies = null, array $server = [], ?string $content = null): TestResponse
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();

    $response = $test->call($method, str_starts_with($path, 'http') ? $path : LOGIN_FLOW_HOST.'/'.ltrim($path, '/'), [], $cookies ?? $test->jar, [], $server, $content);

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared()) {
            unset($test->jar[$cookie->getName()]);
        } else {
            $test->jar[$cookie->getName()] = $cookie->getValue();
        }
    }

    return $response;
}

/**
 * @param  array<string, mixed>  $updates
 * @param  array<int, array<string, mixed>>  $calls
 * @param  array<string, string>|null  $cookies
 */
function livewireUpdate($test, string $snapshot, array $updates = [], array $calls = [], ?array $cookies = null): TestResponse
{
    return browserRequest($test, 'POST', app('livewire')->getUpdateUri(), $cookies, [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => '*/*',
        'HTTP_X_LIVEWIRE'     => '1',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
    ], json_encode([
        '_token'     => '',
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]));
}

/** The first Livewire snapshot on the page whose data contains the marker. */
function pageSnapshot(string $html, string $marker): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $candidate) {
        $snapshot = html_entity_decode($candidate, ENT_QUOTES);

        if (str_contains($snapshot, $marker)) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No Livewire snapshot containing [{$marker}] on the page.");
}

/** Submit the login form of a loaded login page (the browser's "Sign in" click). */
function submitLogin($test, TestResponse $loginPage, User $user, bool $remember = false): TestResponse
{
    return livewireUpdate($test, pageSnapshot($loginPage->getContent(), 'userUndertakingMultiFactorAuthentication'), [
        'data.email'    => $user->email,
        'data.password' => LOGIN_FLOW_PASSWORD,
        'data.remember' => $remember,
    ], [['method' => 'authenticate', 'params' => [], 'metadata' => []]]);
}

/** Where Livewire sends the browser after the call (the "redirect" effect). */
function redirectEffect(TestResponse $response): ?string
{
    return $response->json('components.0.effects.redirect');
}

function loginFlowUser(): User
{
    $company = Company::factory()->create(['is_active' => true]);

    $user = User::factory()->create([
        'email'               => 'login-flow-'.uniqid().'@example.test',
        'password'            => Hash::make(LOGIN_FLOW_PASSWORD),
        'default_company_id'  => $company->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::GLOBAL,
    ]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

it('signs in once and lands on the page that asked for the login, on the host the browser used', function () {
    $user = loginFlowUser();

    browserRequest($this, 'GET', '/admin/employees/org-chart')
        ->assertRedirect(LOGIN_FLOW_HOST.'/admin/login');

    $loginPage = browserRequest($this, 'GET', '/admin/login')->assertOk();
    expect($loginPage->headers->get('Cache-Control'))->toContain('no-store');

    $login = submitLogin($this, $loginPage, $user)->assertOk();

    // APP_URL is :8000 here; the redirect must still stay on the :8080 host the browser is on.
    expect(redirectEffect($login))->toBe(LOGIN_FLOW_HOST.'/admin/employees/org-chart');

    browserRequest($this, 'GET', redirectEffect($login))->assertOk();

    // A signed-in response still renews the session cookie (sliding lifetime).
    browserRequest($this, 'GET', '/admin')->assertOk()->assertCookie('aureuserp_session');
});

it('stays signed in when another tab\'s request, sent with the pre-login cookie, finishes after the login', function () {
    $user = loginFlowUser();

    // Earlier visit: signed in, with the dashboard open in a second tab.
    $firstLogin = submitLogin($this, browserRequest($this, 'GET', '/admin/login'), $user)->assertOk();
    $dashboard = browserRequest($this, 'GET', redirectEffect($firstLogin))->assertOk();
    $otherTabSnapshot = pageSnapshot($dashboard->getContent(), '"path":"admin"');

    // Two hours and more later the session has expired and the browser has dropped the cookie.
    $this->travel(3)->hours();
    $this->jar = [];

    browserRequest($this, 'GET', '/admin')->assertRedirect(LOGIN_FLOW_HOST.'/admin/login');
    $loginPage = browserRequest($this, 'GET', '/admin/login')->assertOk();
    $preLoginCookies = $this->jar;

    // The browser fetches the manifest without credentials.
    $manifest = browserRequest($this, 'GET', '/manifest.webmanifest', cookies: [])->assertOk();
    expect($manifest->headers->getCookies())->toBeEmpty();

    // The other tab polls (database notifications every 60s) just before "Sign in"...
    $login = submitLogin($this, $loginPage, $user)->assertOk();
    expect(redirectEffect($login))->toBe(LOGIN_FLOW_HOST.'/admin');

    // ...and that poll, and the login page it then reloads, answer after the login.
    livewireUpdate($this, $otherTabSnapshot, cookies: $preLoginCookies)
        ->assertRedirect(LOGIN_FLOW_HOST.'/admin/login');
    browserRequest($this, 'GET', '/admin/login', cookies: $preLoginCookies)->assertOk();

    // The login must survive: the browser follows the redirect effect into the panel.
    browserRequest($this, 'GET', redirectEffect($login))->assertOk();
    browserRequest($this, 'GET', '/admin/employees/org-chart')->assertOk();
});

it('sends a session that no longer matches the password to the panel login instead of a server error', function () {
    $user = loginFlowUser();

    $login = submitLogin($this, browserRequest($this, 'GET', '/admin/login'), $user, remember: true)->assertOk();
    browserRequest($this, 'GET', redirectEffect($login))->assertOk();
    expect(collect(array_keys($this->jar))->contains(fn (string $name): bool => str_starts_with($name, 'remember_web_')))->toBeTrue();

    // The password changes elsewhere (another device, an admin, a re-seed) while the session has ended.
    $user->forceFill(['password' => Hash::make(LOGIN_FLOW_PASSWORD)])->save();
    unset($this->jar['aureuserp_session']);

    browserRequest($this, 'GET', '/admin/employees/org-chart')
        ->assertRedirect(LOGIN_FLOW_HOST.'/admin/login');

    // One fresh login is then enough.
    $relogin = submitLogin($this, browserRequest($this, 'GET', '/admin/login'), $user)->assertOk();
    browserRequest($this, 'GET', redirectEffect($relogin))->assertOk();
});

it('keeps the manifest and health check off the session', function (string $path) {
    $response = browserRequest($this, 'GET', $path, cookies: [])->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
})->with(['/manifest.webmanifest', '/up']);
