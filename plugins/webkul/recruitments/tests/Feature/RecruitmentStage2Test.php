<?php

/**
 * Recruitment Stage 2 (docs/RECRUITMENT_PLUGIN_STATUS_AND_INTEGRATION_ROADMAP.md §15.3):
 *
 *  2.1 A job opening must be approved (ApprovalEngine, line manager -> HR
 *      fallback) before it can be published; the posting lifecycle is
 *      enforced server-side on every save.
 *  2.2 One application per candidate per job: a candidate can apply to
 *      several jobs, and re-applying to the same job never duplicates.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\Employee\Models\Department;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Services\CandidateApplicationService;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

/** @return array{company: Company, department: Department, job: JobPosition} */
function stage2Fixture(): array
{
    // Fresh acting user: a previous test's rolled-back user must not be stamped as creator_id.
    Auth::login(User::factory()->create(['is_active' => true]));

    $company = Company::factory()->create(['is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $job = JobPosition::query()->create([
        'company_id' => $company->id, 'department_id' => $department->id,
        'name'       => 'Fleet Dispatcher', 'is_active' => true,
    ]);

    return compact('company', 'department', 'job');
}

function stage2Candidate(Company $company, string $email): Candidate
{
    $partner = Partner::query()->create(['name' => $email, 'sub_type' => 'individual', 'company_id' => $company->id]);

    return Candidate::query()->create(['company_id' => $company->id, 'name' => $email, 'email_from' => $email, 'partner_id' => $partner->id]);
}

// ---------------------------------------------------------------------
// 2.1 Job opening approval and posting lifecycle
// ---------------------------------------------------------------------

it('starts every new job opening as a draft and refuses to create one already published', function () {
    $f = stage2Fixture();

    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::DRAFT);

    expect(fn () => JobPosition::query()->create([
        'company_id' => $f['company']->id, 'name' => 'Sneaky Post', 'posting_status' => 'published',
    ]))->toThrow(RuntimeException::class, 'starts as a draft');
});

it('refuses to publish, approve or mark pending directly, whoever saves the job', function () {
    $f = stage2Fixture();

    foreach (['published', 'approved', 'closed'] as $status) {
        expect(fn () => $f['job']->refresh()->update(['posting_status' => $status]))
            ->toThrow(RuntimeException::class);
    }

    // Draft -> pending is a legal move, but only with a real pending approval request behind it.
    expect(fn () => $f['job']->refresh()->update(['posting_status' => 'pending_approval']))
        ->toThrow(RuntimeException::class, 'approval request is pending');

    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::DRAFT);
});

it('routes a submitted job to the recruiter\'s line manager, then lets it be published, paused, closed and reopened', function () {
    $f = stage2Fixture();
    $chain = jobOpeningApprovalChain($f['company']);
    $service = app(JobOpeningPublicationService::class);

    $request = $service->submitForApproval($f['job'], $chain['recruiter']);
    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::PENDING_APPROVAL)
        ->and($request->request_type)->toBe(JobPosition::APPROVAL_REQUEST_TYPE)
        ->and($request->context['summary'])->toBe('Publish job opening: Fleet Dispatcher')
        ->and(app(ApprovalEngine::class)->resolveAuthorizedApprovers($request)->pluck('id')->all())->toBe([$chain['manager']->id]);

    // Not publishable while the approval is pending.
    expect(fn () => $service->publish($f['job']->refresh(), $chain['recruiter']))
        ->toThrow(RuntimeException::class, 'after its approval');

    app(ApprovalEngine::class)->approve($request, $chain['manager'], 'Headcount approved');
    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::APPROVED);

    $service->publish($f['job'], $chain['recruiter']);
    $publishedAt = $f['job']->published_at;
    expect($f['job']->postingStatus())->toBe(JobPostingStatus::PUBLISHED)
        ->and($publishedAt)->not->toBeNull()
        ->and($f['job']->isPubliclyVisible())->toBeTrue();

    $service->pause($f['job'], $chain['recruiter']);
    expect($f['job']->postingStatus())->toBe(JobPostingStatus::PAUSED)
        ->and($f['job']->isPubliclyVisible())->toBeFalse();

    // Resuming keeps the original publication date.
    $service->publish($f['job'], $chain['recruiter']);
    expect($f['job']->published_at->equalTo($publishedAt))->toBeTrue();

    $service->close($f['job'], $chain['recruiter']);
    expect($f['job']->postingStatus())->toBe(JobPostingStatus::CLOSED);

    // Reopening needs a fresh approval: the old approved request does not count.
    $service->reopen($f['job'], $chain['recruiter']);
    expect($f['job']->postingStatus())->toBe(JobPostingStatus::DRAFT)
        ->and($f['job']->published_at)->toBeNull();
    expect(fn () => $service->publish($f['job'], $chain['recruiter']))->toThrow(RuntimeException::class);
});

it('sends a rejected job back to draft, keeps the reason, and allows a corrected resubmission', function () {
    $f = stage2Fixture();
    $chain = jobOpeningApprovalChain($f['company']);
    $service = app(JobOpeningPublicationService::class);

    $request = $service->submitForApproval($f['job'], $chain['recruiter']);
    $rejected = app(ApprovalEngine::class)->reject($request, $chain['manager'], 'No budget this quarter');

    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::DRAFT)
        ->and($rejected->status)->toBe('rejected')
        ->and($rejected->decisions->first()->reason)->toBe('No budget this quarter');

    $second = $service->submitForApproval($f['job']->refresh(), $chain['recruiter']);
    expect($second->id)->not->toBe($request->id)
        ->and($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::PENDING_APPROVAL);
});

it('does not let the recruiter approve their own job, or a user without edit rights submit one', function () {
    $f = stage2Fixture();
    $chain = jobOpeningApprovalChain($f['company']);
    $service = app(JobOpeningPublicationService::class);

    $outsider = jobOpeningUser($f['company']);
    expect(fn () => $service->submitForApproval($f['job'], $outsider))
        ->toThrow(RuntimeException::class, 'not allowed');

    $request = $service->submitForApproval($f['job'], $chain['recruiter']);
    expect(fn () => app(ApprovalEngine::class)->approve($request, $chain['recruiter']))
        ->toThrow(RuntimeException::class, 'not an approver');

    // A second submission while one is pending is refused.
    expect(fn () => $service->submitForApproval($f['job']->refresh(), $chain['recruiter']))
        ->toThrow(RuntimeException::class, 'Only a draft');
});

it('refuses a job from a company the recruiter cannot access', function () {
    $f = stage2Fixture();
    $chain = jobOpeningApprovalChain($f['company']);
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $foreignJob = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Foreign Job', 'is_active' => true]);

    expect(fn () => app(JobOpeningPublicationService::class)->submitForApproval($foreignJob, $chain['recruiter']))
        ->toThrow(RuntimeException::class, 'cannot access');
});

it('gives a newly created company its job opening approval workflow automatically', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $workflow = ApprovalWorkflow::query()
        ->where('company_id', $company->id)
        ->where('request_type', JobPosition::APPROVAL_REQUEST_TYPE)
        ->first();

    expect($workflow)->not->toBeNull()
        ->and($workflow->steps()->pluck('hierarchy_route')->all())->toBe(['requester_manager']);
});

it('still lets a job published before approvals existed be paused or closed', function () {
    $f = stage2Fixture();
    $chain = jobOpeningApprovalChain($f['company']);
    DB::table('employees_job_positions')->where('id', $f['job']->id)->update(['posting_status' => 'published', 'published_at' => now()]);

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $chain['recruiter']);

    expect($f['job']->postingStatus())->toBe(JobPostingStatus::CLOSED);
});

// ---------------------------------------------------------------------
// 2.2 One application per candidate per job
// ---------------------------------------------------------------------

it('lets one candidate apply to two jobs, each with its own application', function () {
    $f = stage2Fixture();
    $secondJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'department_id' => $f['department']->id, 'name' => 'Warehouse Lead', 'is_active' => true]);
    $candidate = stage2Candidate($f['company'], 'two.jobs@example.test');
    $service = app(CandidateApplicationService::class);

    $first = $service->applyToJob($candidate, $f['job'], Auth::user());
    $second = $service->applyToJob($candidate, $secondJob, Auth::user());

    expect($first['outcome'])->toBe(CandidateApplicationService::OUTCOME_CREATED)
        ->and($second['outcome'])->toBe(CandidateApplicationService::OUTCOME_CREATED)
        ->and($first['applicant']->id)->not->toBe($second['applicant']->id)
        ->and($first['applicant']->job_id)->toBe($f['job']->id)
        ->and($second['applicant']->job_id)->toBe($secondJob->id)
        ->and($first['applicant']->department_id)->toBe($f['department']->id)
        ->and(Applicant::query()->where('candidate_id', $candidate->id)->count())->toBe(2);
});

it('never duplicates an application for the same job, and does not reopen a refused one for a different job', function () {
    $f = stage2Fixture();
    $secondJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Warehouse Lead', 'is_active' => true]);
    $candidate = stage2Candidate($f['company'], 'repeat@example.test');
    $service = app(CandidateApplicationService::class);

    $first = $service->applyToJob($candidate, $f['job'], Auth::user());
    $again = $service->applyToJob($candidate, $f['job'], Auth::user());
    expect($again['outcome'])->toBe(CandidateApplicationService::OUTCOME_EXISTING)
        ->and($again['applicant']->id)->toBe($first['applicant']->id);

    // Refused for job 1; applying to job 2 must leave job 1's refusal untouched.
    $reason = RefuseReason::query()->create(['name' => 'Not a fit', 'template' => 'refuse']);
    $first['applicant']->setAsRefused($reason->id);
    $service->applyToJob($candidate, $secondJob, Auth::user());

    expect($first['applicant']->refresh()->application_status)->toBe(ApplicationStatus::REFUSED);
});

it('reopens a refused application when the candidate is added to the same job again', function () {
    $f = stage2Fixture();
    $candidate = stage2Candidate($f['company'], 'comeback@example.test');
    $service = app(CandidateApplicationService::class);

    $first = $service->applyToJob($candidate, $f['job'], Auth::user());
    $reason = RefuseReason::query()->create(['name' => 'Timing', 'template' => 'refuse']);
    $first['applicant']->setAsRefused($reason->id);

    $reopened = $service->applyToJob($candidate, $f['job'], Auth::user());

    expect($reopened['outcome'])->toBe(CandidateApplicationService::OUTCOME_REOPENED)
        ->and($reopened['applicant']->id)->toBe($first['applicant']->id)
        ->and($reopened['applicant']->application_status)->toBe(ApplicationStatus::ONGOING);
});

it('refuses to apply a candidate to another company\'s job', function () {
    $f = stage2Fixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $foreignJob = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Foreign Job', 'is_active' => true]);
    $candidate = stage2Candidate($f['company'], 'cross@example.test');

    expect(fn () => app(CandidateApplicationService::class)->applyToJob($candidate, $foreignJob, Auth::user()))
        ->toThrow(RuntimeException::class, 'different company');
});

it('saves an unrelated edit of a draft job without tripping the status guard', function () {
    $f = stage2Fixture();

    // Mirrors a Filament form save, which always sends the unchanged status back.
    $f['job']->update(['posting_status' => 'draft', 'description' => 'Updated job description.']);

    expect($f['job']->refresh()->description)->toBe('Updated job description.')
        ->and($f['job']->postingStatus())->toBe(JobPostingStatus::DRAFT);
});

it('asks for the job in the "New application" form and adds the same candidate to a second job', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = stage2Fixture();
    $secondJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Warehouse Lead', 'is_active' => true]);
    $candidate = stage2Candidate($f['company'], 'form.flow@example.test');
    $recruiter = jobOpeningRoleUser($f['company'], 'recruiter');
    test()->actingAs($recruiter);

    Livewire::test(ListApplicants::class)
        ->callAction('create', data: ['candidate_id' => $candidate->id, 'job_id' => $f['job']->id])
        ->assertHasNoActionErrors();
    Livewire::test(ListApplicants::class)
        ->callAction('create', data: ['candidate_id' => $candidate->id, 'job_id' => $secondJob->id])
        ->assertHasNoActionErrors();
    Livewire::test(ListApplicants::class)
        ->callAction('create', data: ['candidate_id' => $candidate->id, 'job_id' => $f['job']->id])
        ->assertHasNoActionErrors();
    // The action redirects to the application afterwards, so the notice is flashed to the session.
    expect(collect(session('filament.notifications'))->pluck('title')->all())->toContain('Already applied');

    expect(Applicant::query()->where('candidate_id', $candidate->id)->orderBy('job_id')->pluck('job_id')->all())
        ->toBe([$f['job']->id, $secondJob->id]);

    Livewire::test(ListApplicants::class)
        ->callAction('create', data: ['candidate_id' => $candidate->id])
        ->assertHasActionErrors(['job_id' => 'required']);
});
