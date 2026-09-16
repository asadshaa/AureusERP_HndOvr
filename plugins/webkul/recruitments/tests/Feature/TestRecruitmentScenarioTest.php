<?php

/**
 * TEST RECRUITMENT -- the 15-item scenario, run against real data through
 * the existing Recruitments plugin (Section 7's workflow): Hiring
 * Requirement -> Job Description -> Job Posting -> Candidate Pool ->
 * Screening -> Interview -> Assessment (where configured) -> Offer ->
 * Hired -> Employee Conversion, plus duplicate-conversion protection,
 * recruitment-history linkage, rejected-candidate protection, and company
 * isolation.
 *
 * Items 14 and 15 surfaced two real, previously-unguarded gaps while this
 * file was being written -- fixed before writing the assertions that prove
 * them, not just reported:
 *
 *   - CandidateConversionService::convert() never checked refuse_reason_id,
 *     so a rejected application could still be silently converted to a real
 *     employee. None of the four "Convert to Employee" UI entry points
 *     (ApplicantResource's row action, ViewApplicant, EditApplicant) hid the
 *     button for a rejected application either -- confirmed by reading each
 *     one's ->hidden()/->visible() condition directly. Fixed by adding a
 *     refuse_reason_id guard to convert() (protects every entry point,
 *     including the two Candidate-page ones that funnel through the same
 *     service) and to the three Applicant-side visibility conditions.
 *   - The job-company-mismatch guard in convert() already existed but had
 *     no test anywhere; item 15 adds one.
 *
 * Item 14's UI check only exercises ListApplicants' table row action, not
 * EditApplicant's: Livewire::test(EditApplicant::class, ['record' => ...])
 * hard-crashes the PHP process in this environment (zero output, exit code
 * 2, reproduces in isolation) even though the same ->hidden() fix pattern is
 * applied there. Confirmed unrelated to that fix -- it crashes identically
 * before and after. The row-action check already empirically proves the fix
 * works; EditApplicant's own condition is the same one-line refuse_reason_id
 * addition already proven correct on ViewApplicant and ApplicantResource.
 */

use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Seeders\RecruitmentWorkflowSeeder;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;

function recruitmentScenarioFixture(): array
{
    // Same fresh-auth fix as RecruitmentWorkflowTest: Pest reuses the app
    // instance across tests in this file, so a prior test's rolled-back
    // actingAs() user would otherwise leak forward as a dangling Auth::id().
    $systemUser = User::factory()->create(['is_active' => true]);
    Auth::login($systemUser);

    $company = Company::factory()->create(['is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $officeAddress = Partner::query()->create(['name' => 'Lahore Warehouse', 'sub_type' => 'company', 'company_id' => $company->id]);
    $recruiter = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $interviewer = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $source = UTMSource::query()->firstOrCreate(['name' => 'LinkedIn']);
    $medium = UTMMedium::query()->firstOrCreate(['name' => 'Organic']);

    app(RecruitmentWorkflowSeeder::class)->run();

    return compact('company', 'department', 'officeAddress', 'recruiter', 'interviewer', 'source', 'medium');
}

function recruitmentScenarioHiringManager(Company $company): User
{
    // The real seeded Hiring Manager role, not a hand-rolled one -- it needs
    // more than just ConvertCandidates to even load the Applicant edit page
    // (the base view/update resource permissions Recruiter already has).
    app(HrRoleSeeder::class)->run();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hiring_manager'])->firstOrFail();

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

// ---------------------------------------------------------------------
// 1-3. Hiring requirement, Job Description, Job Posting.
// ---------------------------------------------------------------------
it('1-3. PASS: creates a hiring requirement, verifies the Job Description, and publishes the job', function () {
    $f = recruitmentScenarioFixture();

    // 1. Hiring requirement: the workforce-planning fields on the position.
    $job = JobPosition::query()->create([
        'company_id'         => $f['company']->id,
        'department_id'      => $f['department']->id,
        'address_id'         => $f['officeAddress']->id,
        'recruiter_id'       => $f['recruiter']->id,
        'name'               => 'Fleet Dispatch Officer',
        'no_of_recruitment'  => 2,
        'expected_employees' => 5,
        'is_active'          => true,
        'posting_status'     => 'draft',
    ]);
    expect($job->no_of_recruitment)->toBe(2)
        ->and($job->department_id)->toBe($f['department']->id)
        ->and($job->company_id)->toBe($f['company']->id);

    // 2. Job Description: description/requirements/location, verified.
    $job->update([
        'description'  => 'Coordinate daily fleet dispatch and driver schedules across Lahore routes.',
        'requirements' => '2+ years dispatch/logistics experience.',
    ]);
    $job->refresh();
    expect($job->description)->not->toBeEmpty()
        ->and($job->requirements)->not->toBeEmpty()
        ->and($job->address_id)->toBe($f['officeAddress']->id); // Job Location preserved

    // 3. Publish job.
    expect($job->posting_status)->toBe('draft')
        ->and($job->published_at)->toBeNull();

    $job->update([
        'posting_status'   => 'published',
        'published_at'     => now(),
        'posting_channels' => ['company_website', 'linkedin'],
    ]);
    $job->refresh();
    expect($job->posting_status)->toBe('published')
        ->and($job->published_at)->not->toBeNull()
        ->and($job->posting_channels)->toBe(['company_website', 'linkedin'])
        ->and($job->description)->not->toBeEmpty(); // JD untouched by the posting-lifecycle change
});

// ---------------------------------------------------------------------
// 4-13. Candidate pool -> screening -> interview -> assessment -> offer ->
// hired -> conversion -> duplicate protection -> history linkage.
// ---------------------------------------------------------------------
it('4-13. PASS: a candidate moves through the full pipeline to conversion, with duplicate protection and history intact', function () {
    $f = recruitmentScenarioFixture();
    $screening = Stage::query()->where('name', 'Screening')->whereNull('company_id')->firstOrFail();
    $interview = Stage::query()->where('name', 'Interview')->whereNull('company_id')->firstOrFail();
    $assessment = Stage::query()->where('name', 'Assessment')->whereNull('company_id')->firstOrFail();
    $offer = Stage::query()->where('name', 'Offer')->whereNull('company_id')->firstOrFail();

    $job = JobPosition::query()->create([
        'company_id'      => $f['company']->id,
        'department_id'   => $f['department']->id,
        'address_id'      => $f['officeAddress']->id,
        'recruiter_id'    => $f['recruiter']->id,
        'name'            => 'Warehouse Supervisor',
        'description'     => 'Supervise inbound/outbound warehouse operations.',
        'is_active'       => true,
        'posting_status'  => 'published',
        'published_at'    => now(),
    ]);

    // 4. Add candidate: pool entry (candidate + application), not yet staged.
    $candidate = Candidate::query()->create([
        'company_id'       => $f['company']->id,
        'name'             => 'Hamza Tariq',
        'email_from'       => 'hamza.tariq@example.test',
        'phone'            => '+92 301 9876543',
        'resume_path'      => 'candidates/resumes/hamza-tariq.pdf',
        'linkedin_profile' => 'https://linkedin.com/in/hamza-tariq',
        'source_reference' => 'LinkedIn job ad',
    ]);
    $application = Applicant::query()->create([
        'company_id'    => $f['company']->id,
        'candidate_id'  => $candidate->id,
        'job_id'        => $job->id,
        'department_id' => $f['department']->id,
        'source_id'     => $f['source']->id,
        'medium_id'     => $f['medium']->id,
        'recruiter_id'  => $f['recruiter']->id,
        'create_date'   => now(),
    ]);
    // application_status is ONGOING (not ARCHIVED) with no explicit is_active
    // passed -- exercising the Section 7 handleApplicationCreation() default.
    expect($application->application_status)->toBe(ApplicationStatus::ONGOING)
        ->and($application->stage_id)->toBeNull();

    // 5. Screen candidate.
    $application->update(['stage_id' => $screening->id, 'screening_score' => 8.0]);

    // 6. Interview.
    $application->update(['stage_id' => $interview->id, 'interview_score' => 8.5]);
    $application->interviewer()->sync([$f['interviewer']->id]);

    // 7. Assessment where configured.
    $application->update(['stage_id' => $assessment->id, 'assessment_score' => 7.0]);

    // 8. Offer.
    $application->update(['stage_id' => $offer->id, 'offer_status' => 'sent', 'offer_date' => now(), 'salary_proposed' => 180000]);

    $application->refresh();
    expect($application->stage_id)->toBe($offer->id)
        ->and((float) $application->screening_score)->toBe(8.0)
        ->and((float) $application->interview_score)->toBe(8.5)
        ->and((float) $application->assessment_score)->toBe(7.0)
        ->and($application->offer_status)->toBe('sent')
        ->and($application->interviewer->pluck('id')->all())->toBe([$f['interviewer']->id]);

    // 9. Mark hired -- a standalone pipeline action, independent of conversion.
    $application->setAsHired();
    $application->refresh();
    expect($application->application_status)->toBe(ApplicationStatus::HIRED)
        ->and($application->date_closed)->not->toBeNull();

    // 10. Convert candidate to employee.
    $employee = app(CandidateConversionService::class)->convert($application);
    expect($employee)->not->toBeNull()
        ->and($employee->name)->toBe('Hamza Tariq')
        ->and($employee->company_id)->toBe($f['company']->id)
        ->and($employee->job_id)->toBe($job->id)
        ->and($employee->department_id)->toBe($f['department']->id);

    // 11. Attempt second conversion.
    $second = app(CandidateConversionService::class)->convert($application->fresh());

    // 12. Verify duplicate protection: same employee, no second row created.
    expect($second->id)->toBe($employee->id)
        ->and(Employee::where('partner_id', $candidate->fresh()->partner_id)->count())->toBe(1);

    // 13. Verify recruitment history remains linked.
    $candidate->refresh();
    $application->refresh();
    expect($candidate->employee_id)->toBe($employee->id)
        ->and(Candidate::where('employee_id', $employee->id)->first()->id)->toBe($candidate->id) // reverse-traceable
        ->and($employee->partner_id)->toBe($candidate->partner_id) // shared contact record
        ->and($application->candidate_id)->toBe($candidate->id)
        ->and($application->job_id)->toBe($job->id)
        ->and($application->messages()->count())->toBeGreaterThan(0); // application history (chatter audit trail)
});

// ---------------------------------------------------------------------
// 7 (addendum). Assessment is genuinely optional, not a hardcoded step.
// ---------------------------------------------------------------------
it('7. PASS: assessment is skippable -- a pipeline can move Interview straight to Offer when not configured', function () {
    $f = recruitmentScenarioFixture();
    $interview = Stage::query()->where('name', 'Interview')->whereNull('company_id')->firstOrFail();
    $offer = Stage::query()->where('name', 'Offer')->whereNull('company_id')->firstOrFail();

    $job = JobPosition::query()->create([
        'company_id' => $f['company']->id, 'department_id' => $f['department']->id, 'name' => 'Driver', 'is_active' => true,
    ]);
    $candidate = Candidate::query()->create([
        'company_id' => $f['company']->id, 'name' => 'Usman Riaz', 'email_from' => 'usman.riaz@example.test',
    ]);
    $application = Applicant::query()->create([
        'company_id'      => $f['company']->id,
        'candidate_id'    => $candidate->id,
        'job_id'          => $job->id,
        'department_id'   => $f['department']->id,
        'stage_id'        => $interview->id,
        'interview_score' => 7.5,
        'create_date'     => now(),
    ]);

    // Straight to Offer -- never stops at Assessment.
    $application->update(['stage_id' => $offer->id, 'offer_status' => 'sent', 'offer_date' => now()]);
    $application->setAsHired();
    $employee = app(CandidateConversionService::class)->convert($application);

    expect($application->fresh()->assessment_score)->toBeNull()
        ->and($employee)->not->toBeNull()
        ->and($application->fresh()->application_status)->toBe(ApplicationStatus::HIRED);
});

// ---------------------------------------------------------------------
// 14. A rejected candidate must not be convertible, accidentally or
// otherwise -- neither through the service nor through the UI.
// ---------------------------------------------------------------------
it('14. PASS: a rejected candidate cannot be converted to an employee, in the service or the UI', function () {
    $f = recruitmentScenarioFixture();
    $refuseReason = RefuseReason::query()->create(['name' => 'Failed the screening call']);
    $candidate = Candidate::query()->create([
        'company_id' => $f['company']->id, 'name' => 'Not A Fit', 'email_from' => 'notafit@example.test',
    ]);
    $application = Applicant::query()->create([
        'company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'create_date' => now(),
    ]);

    $application->setAsRefused($refuseReason->id);
    $application->refresh();
    expect($application->application_status)->toBe(ApplicationStatus::REFUSED);

    expect(fn () => app(CandidateConversionService::class)->convert($application))
        ->toThrow(RuntimeException::class, 'rejected');
    expect(fn () => $application->fresh()->createEmployee())
        ->toThrow(RuntimeException::class, 'rejected');

    expect($candidate->fresh()->employee_id)->toBeNull()
        ->and(Employee::where('partner_id', $candidate->fresh()->partner_id)->exists())->toBeFalse();

    // UI: the "Convert to employee" action is not even shown once rejected,
    // for a user who would otherwise be allowed to use it. ApplicantPolicy::
    // update() scopes edit access to the application's own recruiter (see
    // HasScopedPermissions::hasAccess()), so the acting user must be that
    // recruiter to even load the edit page -- not related to this fix.
    $actingUser = recruitmentScenarioHiringManager($f['company']);
    $application->update(['recruiter_id' => $actingUser->id]);
    Auth::login($actingUser);

    Livewire::test(ListApplicants::class)
        ->assertTableActionHidden('convert_to_employee', $application);
});

// ---------------------------------------------------------------------
// 15. Company isolation is enforced at the point of conversion.
// ---------------------------------------------------------------------
it('15. PASS: conversion is blocked across company boundaries -- existing employee elsewhere, mismatched department, mismatched job', function () {
    $f = recruitmentScenarioFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);

    // (a) candidate already an employee in a DIFFERENT company.
    $otherEmployee = Employee::query()->create(['company_id' => $otherCompany->id, 'name' => 'Elsewhere Hire', 'is_active' => true]);
    $candidateA = Candidate::query()->create([
        'company_id' => $f['company']->id, 'name' => 'Cross Co', 'email_from' => 'crossco@example.test', 'employee_id' => $otherEmployee->id,
    ]);
    $applicationA = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidateA->id, 'create_date' => now()]);
    expect(fn () => app(CandidateConversionService::class)->convert($applicationA))
        ->toThrow(RuntimeException::class, 'another company');

    // (b) application's department belongs to a different company.
    $otherDepartment = Department::factory()->create(['company_id' => $otherCompany->id, 'manager_id' => null]);
    $candidateB = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Dept Cross', 'email_from' => 'deptcross@example.test']);
    $applicationB = Applicant::query()->create([
        'company_id' => $f['company']->id, 'candidate_id' => $candidateB->id, 'department_id' => $otherDepartment->id, 'create_date' => now(),
    ]);
    expect(fn () => app(CandidateConversionService::class)->convert($applicationB))
        ->toThrow(RuntimeException::class, 'department does not belong');

    // (c) application's job belongs to a different company.
    $otherJob = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Other Co Role', 'is_active' => true]);
    $candidateC = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Job Cross', 'email_from' => 'jobcross@example.test']);
    $applicationC = Applicant::query()->create([
        'company_id' => $f['company']->id, 'candidate_id' => $candidateC->id, 'job_id' => $otherJob->id, 'create_date' => now(),
    ]);
    expect(fn () => app(CandidateConversionService::class)->convert($applicationC))
        ->toThrow(RuntimeException::class, 'job does not belong');

    expect(Employee::whereIn('name', ['Cross Co', 'Dept Cross', 'Job Cross'])->exists())->toBeFalse();
});
