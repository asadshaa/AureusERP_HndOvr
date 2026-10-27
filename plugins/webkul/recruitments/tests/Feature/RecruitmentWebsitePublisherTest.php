<?php

/**
 * Company careers website, publishing side (docs/RECRUITMENT_AUTOMATION_PLAN.md
 * §6 A3, API contract §7): one encrypted WordPress connection per company, the
 * "Company website" channel listing a published job through it (PUT/DELETE
 * jobs/{token}), Google for Jobs and the Indeed feed following that listing,
 * apply links pointing at the website job page with ?ref=, failures recorded
 * on the ad with Retry, and the settings page behind its own permission.
 * Every HTTP call is faked.
 */

use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\CareersWebsite;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Recruitment\Services\JobPostingTextBuilder;
use Webkul\Recruitment\Services\Website\CompanyWebsitePublisher;
use Webkul\Recruitment\Services\Website\WebsiteConnectionService;
use Webkul\Recruitment\Services\Website\WebsiteDerivedChannelPublisher;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/WebsiteHelpers.php';

beforeEach(function (): void {
    Sleep::fake();
});

function websiteJobsUrl(string $token): string
{
    return "https://careers.example.com/wp-json/aureus/v1/jobs/{$token}";
}

// ---------------------------------------------------------------------
// Connection
// ---------------------------------------------------------------------

it('stores the application password encrypted and never serialises it', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $connection = websiteConnectionFor($company);

    $raw = DB::table('recruitments_website_connections')->where('id', $connection->id)->value('app_password');

    expect($raw)->not->toBe(WEBSITE_TEST_PASSWORD)
        ->and($raw)->not->toContain('EFGH')
        ->and(decrypt($raw, false))->toBe(WEBSITE_TEST_PASSWORD)
        ->and($connection->fresh()->app_password)->toBe(WEBSITE_TEST_PASSWORD)
        ->and($connection->fresh()->toArray())->not->toHaveKey('app_password')
        ->and($connection->apiBaseUrl())->toBe('https://careers.example.com/wp-json/aureus/v1');
});

it('accepts only a plain https website address, one per company', function () {
    $company = Company::factory()->create(['is_active' => true]);

    foreach (['http://careers.example.com', 'https://user:secret@careers.example.com', 'https://careers.example.com/?x=1', 'not a url'] as $bad) {
        expect(fn () => websiteConnectionFor($company, ['base_url' => $bad]))->toThrow(ValidationException::class);
    }

    $connection = websiteConnectionFor($company, ['base_url' => ' https://careers.example.com/ ']);
    expect($connection->base_url)->toBe('https://careers.example.com')
        ->and(fn () => websiteConnectionFor($company))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------
// Publishing the job
// ---------------------------------------------------------------------

it('lists a published job on its company website and keeps the page address as the ad link', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();
    DB::table('employees_job_positions')->where('id', $f['job']->id)->update(['slug' => null, 'salary_min' => 120000, 'salary_max' => 160000, 'salary_visible' => false]);

    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);

    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($careers->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($careers->live_url)->toBe('https://careers.example.com/careers/fleet-dispatcher')
        ->and($f['job']->refresh()->slug)->toBe('fleet-dispatcher')
        ->and(app(JobPostingChannelRegistry::class)->publisherFor('careers_site'))->toBeInstanceOf(CompanyWebsitePublisher::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === websiteJobsUrl($careers->token)
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('aureus-integration:'.WEBSITE_TEST_PASSWORD))
        && $request['title'] === 'Fleet Dispatcher'
        && $request['status'] === 'published'
        && $request['slug'] === 'fleet-dispatcher'
        && $request['location']['city'] === 'Karachi'
        && $request['salary']['min'] === null
        && $request['salary']['visible'] === false);
    Http::assertSentCount(1);
    expect($site->jobs)->toHaveKey($careers->token);
});

it('takes the job off the website on pause and close, and lists it again on resume', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);
    $careers = jobPostingFor($f['job'], 'careers_site');

    app(JobOpeningPublicationService::class)->pause($f['job']->refresh(), $f['chain']['recruiter']);
    expect($careers->refresh()->status)->toBe(ChannelPostingStatus::WAITING)
        ->and($site->deleted)->toBe([$careers->token]);

    app(JobOpeningPublicationService::class)->publish($f['job']->refresh(), $f['chain']['recruiter']);
    expect($careers->refresh()->status)->toBe(ChannelPostingStatus::LIVE);

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $careers->refresh();
    expect($careers->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($careers->closedReasonLabel())->toBe('Withdrawn')
        ->and($site->deleted)->toBe([$careers->token, $careers->token]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === websiteJobsUrl($careers->token));
});

it('records a refused request on the ad without retrying it, keeps the job published, and lists it on Retry', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $refuse = true;
    $site = (new FakeWordPressSite)
        ->on('PUT jobs/*', function () use (&$refuse) {
            return $refuse ? Http::response(['code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.'], 401) : null;
        })
        ->install();

    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);

    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($careers->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($careers->last_error)->toContain('401', 'Sorry, you are not allowed to do that.', 'Application Password')
        ->and($careers->last_error)->not->toContain(WEBSITE_TEST_PASSWORD)
        ->and($f['job']->refresh()->postingStatus()->value)->toBe('published');
    Http::assertSentCount(1);

    $refuse = false;
    $retried = app(JobPostingService::class)->retry($careers, $f['manager']);

    expect($retried->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($retried->last_error)->toBeNull()
        ->and($site->jobs)->toHaveKey($careers->token);
});

it('retries a timeout and a server error twice, then records the failure', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $calls = 0;
    $mode = 'timeout';
    (new FakeWordPressSite)
        ->on('PUT jobs/*', function (Request $request) use (&$calls, &$mode) {
            $calls++;

            if ($mode === 'timeout') {
                return Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds');
            }

            return $calls === 1 ? Http::response('Bad gateway', 502) : null;
        })
        ->install();

    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);

    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($calls)->toBe(3)
        ->and($careers->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($careers->last_error)->toContain('Could not reach the careers website', 'timed out');
    Sleep::assertSleptTimes(2);

    // A server error followed by success: listed on the second try.
    $calls = 0;
    $mode = 'flaky';
    expect(app(JobPostingService::class)->retry($careers, $f['manager'])->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($calls)->toBe(2);
});

it('never uses another company\'s website connection', function () {
    $a = jobPostingFixture();
    websiteConnectionFor($a['company']);
    $b = jobPostingFixture();
    $site = (new FakeWordPressSite)->install();

    jobPostingAdd($b, ['careers_site']);
    jobPostingPublish($b);

    expect(jobPostingFor($b['job'], 'careers_site')->status)->toBe(ChannelPostingStatus::WAITING)
        ->and(app(CompanyWebsitePublisher::class)->isAvailable($b['job']))->toBeFalse()
        ->and(app(CompanyWebsitePublisher::class)->isAvailable($a['job']))->toBeTrue();
    Http::assertNothingSent();

    // With its own website, company B's job goes to B's site only.
    websiteConnectionFor($b['company'], ['base_url' => 'https://jobs.company-b.example']);
    $siteB = (new FakeWordPressSite('https://jobs.company-b.example'))->install();
    $careersB = jobPostingFor($b['job'], 'careers_site');
    DB::table('recruitments_job_postings')->where('id', $careersB->id)->update(['status' => 'failed', 'last_error' => 'x']);
    app(JobPostingService::class)->retry($careersB->refresh(), $b['manager']);

    expect($careersB->refresh()->live_url)->toStartWith('https://jobs.company-b.example/careers/')
        ->and($siteB->jobs)->toHaveKey($careersB->token)
        ->and($site->jobs)->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'careers.example.com'));
});

it('does not pretend a listed job was removed when the connection is switched off', function () {
    $f = jobPostingFixture();
    $connection = websiteConnectionFor($f['company']);
    (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);

    $connection->update(['is_active' => false]);
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);

    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($careers->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($careers->last_error)->toContain('could not be taken off the website');
});

it('lists Google for Jobs and the Indeed feed with the website page, and unlists them with it', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    (new FakeWordPressSite)->install();

    jobPostingAdd($f, ['google_jobs', 'indeed_feed']);
    jobPostingPublish($f);

    $careers = jobPostingFor($f['job'], 'careers_site');
    $google = jobPostingFor($f['job'], 'google_jobs');
    $indeed = jobPostingFor($f['job'], 'indeed_feed');

    expect($careers->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($google->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($google->live_url)->toBe($careers->live_url)
        ->and($indeed->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($indeed->live_url)->toBe('https://careers.example.com/careers/feed/indeed.xml')
        ->and(app(JobPostingChannelRegistry::class)->publisherFor('google_jobs'))->toBeInstanceOf(WebsiteDerivedChannelPublisher::class);
    // Google and Indeed read the website itself: only the website was called.
    Http::assertSentCount(1);

    app(JobOpeningPublicationService::class)->pause($f['job']->refresh(), $f['chain']['recruiter']);
    expect($google->refresh()->status)->toBe(ChannelPostingStatus::WAITING)
        ->and($indeed->refresh()->status)->toBe(ChannelPostingStatus::WAITING);

    // Without a website listing they keep waiting, as before.
    $other = jobPostingFixture();
    jobPostingAdd($other, ['google_jobs']);
    jobPostingPublish($other);
    expect(jobPostingFor($other['job'], 'google_jobs')->status)->toBe(ChannelPostingStatus::WAITING);
});

it('pushes edits of a listed job and of its screening questions to the website', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);
    $token = jobPostingFor($f['job'], 'careers_site')->token;

    $f['job']->refresh()->update(['description' => '<p>Plan routes for 40 trucks.</p>']);
    expect($site->jobs[$token]['description_html'])->toBe('<p>Plan routes for 40 trucks.</p>');

    JobQuestion::query()->create(['company_id' => $f['company']->id, 'job_id' => $f['job']->id, 'type' => 'yes_no', 'label' => 'Can you work on-site in Karachi?', 'is_required' => true, 'knockout' => ['required_answer' => 'yes'], 'sort' => 1]);
    expect($site->jobs[$token]['questions'][0]['label'])->toBe('Can you work on-site in Karachi?');

    Http::assertSentCount(3);
});

// ---------------------------------------------------------------------
// Apply links
// ---------------------------------------------------------------------

it('uses the website job page with the ad token as the apply link, and never a local address', function () {
    config(['app.url' => 'http://127.0.0.1:8000', 'recruitment.careers.base_url' => null, 'recruitment.careers.apply_email' => null]);
    $links = app(JobPostingLinks::class);

    $f = jobPostingFixture();
    $connection = websiteConnectionFor($f['company']);
    (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['careers_site', 'linkedin']);
    jobPostingPublish($f);

    $page = jobPostingFor($f['job'], 'careers_site')->live_url;
    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $expected = "{$page}?ref={$linkedin->token}";

    expect($links->linksAreLive($linkedin))->toBeTrue()
        ->and($links->applyUrl($linkedin))->toBe($expected)
        ->and($links->notLiveReason($f['job']))->toBeNull()
        ->and(app(JobPostingTextBuilder::class)->build($linkedin)->fullText)->toContain("Apply here: {$expected}");

    $posted = app(JobPostingService::class)->markLive($linkedin, $f['manager'], 'https://linkedin.com/jobs/view/7');
    expect($posted->apply_url)->toBe($expected);

    // Connection switched off: back to the old behaviour (no link in the ad).
    $connection->update(['is_active' => false]);
    expect($links->linksAreLive($linkedin))->toBeFalse()
        ->and(app(JobPostingTextBuilder::class)->applyLine($linkedin))->toBe('Apply through this post.');

    // A local test site never counts as live.
    $local = jobPostingFixture();
    websiteConnectionFor($local['company'], ['base_url' => 'https://wordpress.test']);
    (new FakeWordPressSite('https://wordpress.test'))->install();
    jobPostingAdd($local, ['careers_site', 'rozee']);
    jobPostingPublish($local);
    $rozee = jobPostingFor($local['job'], 'rozee');

    expect(jobPostingFor($local['job'], 'careers_site')->live_url)->toStartWith('https://wordpress.test/')
        ->and($links->linksAreLive($rozee))->toBeFalse()
        ->and($links->notLiveReason($local['job']))->toContain('not a public HTTPS address')
        ->and(app(JobPostingTextBuilder::class)->applyLine($rozee))->not->toContain('wordpress.test');
});

// ---------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------

it('lets only integration managers set up the active company\'s website, with a write-only password and Test connection', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $company = Company::factory()->create(['is_active' => true]);
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherConnection = websiteConnectionFor($otherCompany, ['base_url' => 'https://other.example.com']);

    test()->actingAs(jobPostingManager($company));
    Livewire::test(CareersWebsite::class)->assertForbidden();

    $manager = websiteIntegrationManager($company);
    test()->actingAs($manager);

    Livewire::test(CareersWebsite::class)
        ->fillForm(['base_url' => 'http://careers.example.com', 'username' => 'aureus-integration', 'app_password' => 'secret', 'is_active' => true])
        ->callAction('save')
        ->assertHasFormErrors(['base_url']);
    expect(WebsiteConnection::query()->where('company_id', $company->id)->exists())->toBeFalse();

    Livewire::test(CareersWebsite::class)
        ->fillForm(['base_url' => 'https://careers.example.com/', 'username' => 'aureus-integration', 'app_password' => WEBSITE_TEST_PASSWORD, 'is_active' => true])
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Careers website saved');

    $connection = WebsiteConnection::query()->where('company_id', $company->id)->sole();
    expect($connection->base_url)->toBe('https://careers.example.com')
        ->and($connection->app_password)->toBe(WEBSITE_TEST_PASSWORD)
        ->and($connection->is_active)->toBeTrue()
        ->and($connection->creator_id)->toBe($manager->id);

    // The password never comes back to the browser, and an empty field keeps it.
    $page = Livewire::test(CareersWebsite::class)->assertFormSet(['base_url' => 'https://careers.example.com', 'app_password' => null]);
    expect(json_encode($page->instance()->data))->not->toContain('EFGH');

    $site = (new FakeWordPressSite)->install();
    $page->fillForm(['username' => 'aureus-bot', 'app_password' => ''])
        ->callAction('testConnection')
        ->assertNotified('Connection works');

    $connection->refresh();
    expect($connection->username)->toBe('aureus-bot')
        ->and($connection->app_password)->toBe(WEBSITE_TEST_PASSWORD)
        ->and($connection->last_checked_at)->not->toBeNull()
        ->and($connection->last_error)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://careers.example.com/wp-json/aureus/v1/ping'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('aureus-bot:'.WEBSITE_TEST_PASSWORD)));

    $site->on('GET ping', fn () => Http::response(['message' => 'No route was found matching the URL and request method.'], 404));
    Livewire::test(CareersWebsite::class)->callAction('testConnection')->assertNotified('Connection failed');
    expect($connection->refresh()->last_error)->toContain('404', 'Aureus Careers plugin');

    // Opened for one company, the page never saves into another one after a company switch.
    $manager->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    CurrentCompany::flush($manager);
    $opened = Livewire::test(CareersWebsite::class)->fillForm(['base_url' => 'https://hijack.example.com']);
    CurrentCompany::switchTo($otherCompany->id, $manager);
    $opened->callAction('save')->assertNotified('The active company changed');

    // The other company's connection is never shown or touched.
    expect($otherConnection->refresh()->base_url)->toBe('https://other.example.com')
        ->and($otherConnection->username)->toBe('aureus-integration')
        ->and($connection->refresh()->base_url)->toBe('https://careers.example.com');
});

it('gives HR administrators the integrations permission, but not recruiters', function () {
    $f = jobPostingFixture();

    expect(jobOpeningRoleUser($f['company'], 'hr_administrator')->can(HrPermissions::ManageRecruitmentIntegrations))->toBeTrue()
        ->and(jobOpeningRoleUser($f['company'], 'recruiter')->can(HrPermissions::ManageRecruitmentIntegrations))->toBeFalse()
        ->and(HrPermissions::all())->toContain(HrPermissions::ManageRecruitmentIntegrations);
});

it('reports a redirect instead of following it with the password', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $connection = websiteConnectionFor($company);
    (new FakeWordPressSite)->on('GET ping', fn () => Http::response('', 301, ['Location' => 'https://www.careers.example.com/wp-json/aureus/v1/ping']))->install();

    $outcome = app(WebsiteConnectionService::class)->test(websiteIntegrationManager($company), $connection);

    expect($outcome['ok'])->toBeFalse()
        ->and($outcome['message'])->toContain('301', 'redirected')
        ->and($connection->refresh()->last_error)->toContain('redirected');
    Http::assertSentCount(1);
});
