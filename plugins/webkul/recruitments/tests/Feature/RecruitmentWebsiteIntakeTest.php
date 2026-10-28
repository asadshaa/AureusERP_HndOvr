<?php

/**
 * Company careers website, intake side (docs/RECRUITMENT_AUTOMATION_PLAN.md
 * §6 A4, API contract §7): recruitments:pull-website-applications imports
 * new applications through ApplicantIntakeService (company_website source)
 * with their ad, CV and screening answers, acknowledges them only after the
 * commit, never imports twice, keeps one application per candidate per job,
 * refuses another company's job, records failures for the Website intake
 * page (Retry / Dismiss) and tells the recruiter. Every HTTP call is faked.
 */

use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\WebsiteIntake;
use Webkul\Recruitment\Jobs\AnalyseApplicantCv;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\IntakeFailure;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Recruitment\Services\Website\WebsiteIntakeService;
use Webkul\Security\Models\User;

require_once __DIR__.'/Support/WebsiteHelpers.php';

beforeEach(function (): void {
    Sleep::fake();
    Queue::fake();
    Storage::fake(CandidateResource::CV_DISK);
});

/**
 * A published job on a connected company website with Rozee and LinkedIn
 * ads and one must-have question; nobody signed in (as on the scheduler).
 *
 * @return array{company: mixed, job: mixed, manager: User, chain: array, connection: WebsiteConnection, site: FakeWordPressSite, question: JobQuestion, token: string}
 */
function websiteIntakeFixture(): array
{
    $f = jobPostingFixture();
    $f['connection'] = websiteConnectionFor($f['company']);
    $f['site'] = (new FakeWordPressSite)->install();
    $f['question'] = JobQuestion::query()->create([
        'company_id' => $f['company']->id, 'job_id' => $f['job']->id, 'type' => 'yes_no',
        'label'      => 'Can you work on-site in Karachi 5 days a week?', 'is_required' => true,
        'knockout'   => ['required_answer' => 'yes'], 'sort' => 1,
    ]);

    jobPostingAdd($f, ['careers_site', 'rozee', 'linkedin']);
    jobPostingPublish($f);
    $f['token'] = jobPostingFor($f['job'], 'careers_site')->token;

    Auth::logout();

    return $f;
}

/** One application as the WordPress plugin lists it (contract §7). */
function websiteApplication(int $id, string $jobToken, array $attributes = []): array
{
    return $attributes + [
        'id'           => $id,
        'job_token'    => $jobToken,
        'ref'          => null,
        'src'          => null,
        'submitted_at' => '2026-11-02T10:15:00Z',
        'name'         => "Applicant {$id}",
        'email'        => "applicant{$id}@example.test",
        'phone'        => '+923001234567',
        'answers'      => [],
        'consent'      => ['privacy' => true, 'ai_screening' => false],
        'cv'           => ['filename' => "applicant-{$id}.pdf", 'size' => 200, 'mime' => 'application/pdf'],
    ];
}

function websitePull(): void
{
    test()->artisan('recruitments:pull-website-applications')->assertSuccessful();
}

function websiteApplicants(array $f)
{
    return Applicant::query()->where('company_id', $f['company']->id)->where('external_application_id', 'like', 'website:%')->orderBy('id')->get();
}

// ---------------------------------------------------------------------
// Importing
// ---------------------------------------------------------------------

it('imports website applications with their ad, CV and screening answers, then acknowledges them', function () {
    $f = websiteIntakeFixture();
    $rozee = jobPostingFor($f['job'], 'rozee');
    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $docx = "PK\x03\x04".str_repeat("\0", 26).'word/document.xml<w:document>Sara Khan</w:document>';

    $f['site']->applications = [
        websiteApplication(981, $f['token'], ['name' => 'Ali Raza', 'email' => 'ali@example.test', 'ref' => $rozee->token, 'src' => 'rozee', 'answers' => [['question_id' => $f['question']->id, 'answer' => 'yes']]]),
        websiteApplication(982, $f['token'], ['name' => 'Sara Khan', 'email' => 'Sara.Khan@Example.TEST', 'src' => 'linkedin', 'answers' => [['question_id' => $f['question']->id, 'answer' => 'no'], ['question_id' => 999999, 'answer' => 'x']], 'cv' => ['filename' => 'sara.docx', 'size' => 90]]),
    ];
    $f['site']->cvs = [981 => ['contents' => websitePdf()], 982 => ['contents' => $docx, 'filename' => 'sara.docx']];

    websitePull();

    [$ali, $sara] = websiteApplicants($f)->all();
    $disk = Storage::disk(CandidateResource::CV_DISK);

    expect(websiteApplicants($f))->toHaveCount(2)
        ->and($ali->external_application_id)->toBe('website:careers.example.com:981')
        ->and($ali->job_id)->toBe($f['job']->id)
        ->and($ali->job_posting_id)->toBe($rozee->id)
        ->and($ali->source_id)->toBe($rozee->refresh()->utm_source_id)
        ->and($ali->source_details)->toBe('Company website via Rozee.pk')
        ->and($ali->creator_id)->toBeNull()
        ->and($ali->screening_status)->toBe(ScreeningStatus::PASSED->value)
        ->and($ali->applicant_properties['source_provenance']['adapter'])->toBe('company_website')
        ->and($ali->applicant_properties['source_provenance']['website'])->toMatchArray(['application_id' => 981, 'site' => 'careers.example.com', 'attribution' => 'ref', 'consent' => ['privacy' => true, 'ai_screening' => false]])
        ->and($disk->get($ali->candidate->resume_path))->toBe(websitePdf())
        ->and($ali->candidate->resume_path)->toStartWith('recruitment/cv/')->toEndWith('.pdf')
        ->and($sara->job_posting_id)->toBe($linkedin->id)
        ->and($sara->candidate->email_from)->toBe('sara.khan@example.test')
        ->and($sara->candidate->resume_path)->toEndWith('.docx')
        ->and($sara->screening_status)->toBe(ScreeningStatus::FLAGGED->value)
        ->and($sara->applicant_properties['source_provenance']['website']['unknown_questions'])->toBe([999999])
        ->and($f['site']->acked)->toBe([981 => $ali->id, 982 => $sara->id])
        ->and($f['connection']->refresh()->last_pulled_at)->not->toBeNull()
        ->and(IntakeFailure::query()->where('company_id', $f['company']->id)->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://careers.example.com/wp-json/aureus/v1/applications?')
        && $request['status'] === 'new' && (int) $request['limit'] === 50);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://careers.example.com/wp-json/aureus/v1/applications/981/cv'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('aureus-integration:'.WEBSITE_TEST_PASSWORD)));
    Queue::assertPushed(AnalyseApplicantCv::class, 2);

    $notices = $f['manager']->notifications()->get()->filter(fn ($n): bool => str_contains((string) ($n->data['title'] ?? ''), 'from the company website'));
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->data['title'])->toContain('2 new application(s)', 'Fleet Dispatcher')
        ->and($notices->first()->data['body'])->toContain('Ali Raza', 'Sara Khan', '1 did not pass');
});

it('never imports an application twice, also when its acknowledgement was lost', function () {
    $f = websiteIntakeFixture();
    $ackWorks = false;
    $f['site']->on('POST applications/*/ack', function () use (&$ackWorks) {
        return $ackWorks ? null : Http::response('Server error', 500);
    });
    $f['site']->applications = [websiteApplication(981, $f['token'])];
    $f['site']->cvs = [981 => ['contents' => websitePdf()]];

    websitePull();
    $applicant = websiteApplicants($f)->sole();
    expect($f['site']->acked)->toBe([]);

    // The website still offers it: the next run finds it imported and only acknowledges.
    $ackWorks = true;
    websitePull();
    websitePull();

    expect(websiteApplicants($f))->toHaveCount(1)
        ->and(Candidate::query()->where('company_id', $f['company']->id)->where('email_from', 'applicant981@example.test')->count())->toBe(1)
        ->and($f['site']->acked)->toBe([981 => $applicant->id])
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/applications/981/cv'))->count())->toBe(1);
});

it('keeps one application per candidate per job and acknowledges the repeat with the existing one', function () {
    $f = websiteIntakeFixture();
    $f['site']->applications = [websiteApplication(981, $f['token'], ['email' => 'ali@example.test'])];
    $f['site']->cvs = [981 => ['contents' => websitePdf()]];
    websitePull();
    $first = websiteApplicants($f)->sole();

    $f['site']->applications[] = websiteApplication(990, $f['token'], ['email' => 'ALI@example.test', 'name' => 'Ali R.']);
    $f['site']->cvs[990] = ['contents' => websitePdf('second')];
    websitePull();

    expect(websiteApplicants($f))->toHaveCount(1)
        ->and($f['site']->acked)->toBe([981 => $first->id, 990 => $first->id]);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/applications/990/cv'));
});

// ---------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------

it('records a CV that is not a PDF or Word file as a failure, tries it a few times, then imports it on Retry', function () {
    $f = websiteIntakeFixture();
    $integration = websiteIntegrationManager($f['company']);
    $cvDownloads = 0;
    $f['site']->on('GET applications/*/cv', function () use (&$cvDownloads) {
        $cvDownloads++;

        return null;
    });
    $f['site']->applications = [
        websiteApplication(981, $f['token'], ['cv' => ['filename' => 'cv.pdf', 'size' => 20]]),
        websiteApplication(982, $f['token'], ['cv' => ['filename' => 'setup.exe', 'size' => 20]]),
    ];
    $f['site']->cvs = [981 => ['contents' => "MZ\x90\x00 this is a program"]];

    websitePull();

    $bad = IntakeFailure::query()->where('external_id', 'website:careers.example.com:981')->sole();
    $exe = IntakeFailure::query()->where('external_id', 'website:careers.example.com:982')->sole();
    expect(websiteApplicants($f))->toHaveCount(0)
        ->and(Candidate::query()->where('email_from', 'applicant981@example.test')->exists())->toBeFalse()
        ->and($bad->company_id)->toBe($f['company']->id)
        ->and($bad->connection_id)->toBe($f['connection']->id)
        ->and($bad->error)->toContain('not a PDF, DOC or DOCX')
        ->and($bad->attempts)->toBe(1)
        ->and($bad->payload['email'])->toBe('applicant981@example.test')
        ->and($exe->error)->toContain('(it is .exe)')
        ->and($f['site']->acked)->toBe([])
        ->and(Storage::disk(CandidateResource::CV_DISK)->allFiles())->toBe([])
        ->and($cvDownloads)->toBe(1);

    $notices = $integration->notifications()->get()->filter(fn ($n): bool => str_contains((string) ($n->data['title'] ?? ''), 'could not be imported'));
    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('data.body')->implode(' '))->toContain('Applicant 981', 'is not a PDF, DOC or DOCX file.');

    // Tried again automatically, then left for HR.
    websitePull();
    websitePull();
    websitePull();
    expect($bad->refresh()->attempts)->toBe(3)
        ->and($cvDownloads)->toBe(3);

    // The candidate sent a proper PDF: HR retries from the Website intake page.
    $f['site']->cvs[981] = ['contents' => websitePdf()];
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($integration);

    Livewire::test(WebsiteIntake::class)
        ->assertCanSeeTableRecords([$bad, $exe])
        ->callTableAction('retry', $bad)
        ->assertNotified('1 new application imported');

    $applicant = websiteApplicants($f)->sole();
    expect($bad->refresh()->resolution)->toBe(IntakeFailure::RESOLVED_IMPORTED)
        ->and($bad->resolved_at)->not->toBeNull()
        ->and($bad->applicant_id)->toBe($applicant->id)
        ->and($bad->payload)->toBeNull()
        ->and($f['site']->acked)->toBe([981 => $applicant->id]);
});

it('refuses an application for a job of another company and records it', function () {
    $f = websiteIntakeFixture();
    $other = jobPostingFixture();
    jobPostingAdd($other, ['careers_site']);
    $otherToken = jobPostingFor($other['job'], 'careers_site')->token;
    Auth::logout();

    $f['site']->applications = [websiteApplication(981, $otherToken, ['email' => 'outsider@example.test'])];
    $f['site']->cvs = [981 => ['contents' => websitePdf()]];

    websitePull();

    $failure = IntakeFailure::query()->where('company_id', $f['company']->id)->sole();
    expect($failure->error)->toContain('another company')
        ->and(Applicant::query()->whereIn('company_id', [$f['company']->id, $other['company']->id])->where('external_application_id', 'like', 'website:%')->exists())->toBeFalse()
        ->and(Candidate::query()->where('email_from', 'outsider@example.test')->exists())->toBeFalse()
        ->and(IntakeFailure::query()->where('company_id', $other['company']->id)->exists())->toBeFalse()
        ->and($f['site']->acked)->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/cv'));

    // The system intake path keeps the company check too.
    expect(fn () => app(ApplicantIntakeService::class)->importForCompany('company_website', [
        'company_id' => $other['company']->id, 'external_application_id' => 'website:x:1', 'name' => 'X', 'email' => 'x@example.test', 'job_id' => $other['job']->id,
    ], $f['company']->id))->toThrow(RuntimeException::class, 'different company')
        ->and(fn () => app(ApplicantIntakeService::class)->importForCompany('company_website', [
            'external_application_id' => 'website:x:1', 'name' => 'X', 'email' => 'x@example.test', 'job_id' => $other['job']->id,
        ], $f['company']->id))->toThrow(ModelNotFoundException::class);
});

it('records an unreadable website on the connection and fails the command', function () {
    $f = websiteIntakeFixture();
    $f['site']->on('GET applications', fn () => Http::response(['message' => 'Sorry, you are not allowed to do that.'], 401));

    test()->artisan('recruitments:pull-website-applications')->assertFailed();

    expect($f['connection']->refresh()->last_pull_error)->toContain('401', 'Application Password')
        ->and(IntakeFailure::query()->where('company_id', $f['company']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// Website intake page
// ---------------------------------------------------------------------

it('shows only the active company\'s open failures to integration managers, and dismisses them for good', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = websiteIntakeFixture();
    $other = websiteIntakeFixture();
    $f['site']->applications = [websiteApplication(981, $f['token'], ['cv' => ['filename' => 'virus.exe']])];
    websitePull();

    $mine = IntakeFailure::query()->where('company_id', $f['company']->id)->sole();
    $theirs = IntakeFailure::query()->create([
        'company_id'  => $other['company']->id, 'connection_id' => $other['connection']->id, 'source' => 'company_website',
        'external_id' => 'website:careers.example.com:5', 'payload' => ['id' => 5, 'name' => 'Other'], 'error' => 'x',
    ]);

    test()->actingAs(jobPostingManager($f['company']));
    Livewire::test(WebsiteIntake::class)->assertForbidden();

    test()->actingAs(websiteIntegrationManager($f['company']));
    Livewire::test(WebsiteIntake::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee('Fleet Dispatcher')
        ->callTableAction('dismiss', $mine)
        ->assertNotified('Dismissed');

    expect($mine->refresh()->resolution)->toBe(IntakeFailure::RESOLVED_DISMISSED)
        ->and($mine->resolved_by)->toBe(Auth::id())
        ->and($f['site']->acked)->toBe([981 => null])
        ->and($theirs->refresh()->isOpen())->toBeTrue();

    // Another company's failure cannot be retried or dismissed through the service either.
    expect(fn () => app(WebsiteIntakeService::class)->dismiss($theirs, Auth::user()))->toThrow(RuntimeException::class, 'not allowed');

    // A dismissed application is never imported, even if the website offers it again.
    $f['site']->acked = [];
    Auth::logout();
    websitePull();
    expect(websiteApplicants($f))->toHaveCount(0)
        ->and($mine->refresh()->attempts)->toBe(1)
        ->and($f['site']->acked)->toBe([981 => null]);
});

it('runs the pull every five minutes, without overlapping, on one server', function () {
    test()->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'recruitments:pull-website-applications'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
