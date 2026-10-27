<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

require_once __DIR__.'/JobPostingHelpers.php';

/**
 * Shared helpers for the careers website tests (automation plan A3/A4). Not a
 * test file itself; require_once it. Nothing here ever makes a real HTTP call.
 */
const WEBSITE_TEST_PASSWORD = 'abcd EFGH 1234 ijkl MNOP 5678';

function websiteConnectionFor(Company $company, array $attributes = []): WebsiteConnection
{
    return WebsiteConnection::query()->create($attributes + [
        'company_id'   => $company->id,
        'base_url'     => 'https://careers.example.com',
        'username'     => 'aureus-integration',
        'app_password' => WEBSITE_TEST_PASSWORD,
        'is_active'    => true,
    ]);
}

/** A user who may connect the careers website of $company (and nothing else). */
function websiteIntegrationManager(Company $company): User
{
    $user = jobOpeningUser($company, HrPermissions::ManageRecruitmentIntegrations);

    // The app's own registrar keeps the permission list in memory too.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A minimal PDF, as far as content sniffing goes. */
function websitePdf(string $text = 'Ali Raza, fleet dispatcher'): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% {$text}\ntrailer << >>\n%%EOF";
}

/**
 * A fake WordPress site with the Aureus Careers plugin (API contract v1).
 * Requests to any other host fail the test (preventStrayRequests).
 * "overrides" map "METHOD path-pattern" to a closure returning a response
 * (or null to fall through to the default behaviour).
 */
class FakeWordPressSite
{
    /** @var array<string, Closure> */
    public array $overrides = [];

    /** @var array<int, array<string, mixed>> what GET applications returns (until acknowledged) */
    public array $applications = [];

    /** @var array<int, array{contents: string, mime?: string, filename?: string}> */
    public array $cvs = [];

    /** @var array<int, ?int> application id => Aureus applicant id */
    public array $acked = [];

    /** @var array<string, array<string, mixed>> token => last payload */
    public array $jobs = [];

    /** @var array<int, string> */
    public array $deleted = [];

    public function __construct(public string $base = 'https://careers.example.com') {}

    public function install(): static
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    public function on(string $route, Closure $handler): static
    {
        $this->overrides[$route] = $handler;

        return $this;
    }

    public function handle(Request $request): mixed
    {
        $prefix = rtrim($this->base, '/').'/wp-json/aureus/v1/';

        if (! str_starts_with($request->url(), $prefix)) {
            // Another fake site may answer; anything else is a stray request and fails the test.
            return null;
        }

        $path = Str::before(Str::after($request->url(), $prefix), '?');
        $route = $request->method().' '.$path;

        foreach ($this->overrides as $pattern => $handler) {
            if (Str::is($pattern, $route) && ($response = $handler($request)) !== null) {
                return $response;
            }
        }

        if ($route === 'GET ping') {
            return Http::response(['ok' => true, 'version' => '1.0.0']);
        }

        if (Str::is('PUT jobs/*', $route)) {
            $token = Str::after($path, 'jobs/');
            $this->jobs[$token] = $request->data();

            return Http::response(['url' => rtrim($this->base, '/').'/careers/'.$request['slug']]);
        }

        if (Str::is('DELETE jobs/*', $route)) {
            $this->deleted[] = Str::after($path, 'jobs/');

            return Http::response(['deleted' => true]);
        }

        if ($route === 'GET applications') {
            return Http::response(array_values(array_filter($this->applications, fn (array $application): bool => ! array_key_exists((int) $application['id'], $this->acked))));
        }

        if (Str::is('GET applications/*/cv', $route)) {
            $cv = $this->cvs[(int) Str::between($path, 'applications/', '/cv')] ?? null;

            return $cv
                ? Http::response($cv['contents'], 200, ['Content-Type' => $cv['mime'] ?? 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.($cv['filename'] ?? 'cv.pdf').'"'])
                : Http::response(['message' => 'No CV'], 404);
        }

        if (Str::is('POST applications/*/ack', $route)) {
            $this->acked[(int) Str::between($path, 'applications/', '/ack')] = $request['aureus_applicant_id'];

            return Http::response(['ok' => true]);
        }

        return Http::response(['message' => 'No route'], 404);
    }
}
