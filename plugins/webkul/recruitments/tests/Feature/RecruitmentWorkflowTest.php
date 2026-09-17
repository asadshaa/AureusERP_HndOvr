<?php

/**
 * Section 7 ("IMPLEMENTATION SECTION 7 -- RECRUITMENT") -- exercises the
 * existing Recruitments plugin's Hiring Requirement -> Job Description ->
 * Job Posting -> Candidate Pool -> Screening -> Interview -> Assessment ->
 * Offer -> Hired/Rejected -> Employee Conversion workflow end to end, using
 * CandidateConversionService as-is. No parallel recruitment system: this
 * only verifies what already exists, plus the one genuine gap closed here
 * -- a named Screening/Interview/Assessment/Offer/Hired stage pipeline
 * (RecruitmentWorkflowSeeder), since the pre-existing demo StageSeeder uses
 * different generic stage names and is destructive (not safe to extend).
 */

use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Seeders\RecruitmentWorkflowSeeder;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;

function recruitmentFixture(): array
{
    // Pest reuses the same app instance across tests in this file; without
    // a fresh authenticated user, an earlier test's actingAs() user (whose
    // row was then rolled back by DatabaseTransactions) can leak forward as
    // Auth::id() here and fail FK checks in boot hooks that stamp
    // creator_id from it (e.g. Candidate::handlePartnerCreation()).
    $systemUser = User::factory()->create(['is_active' => true]);
    Auth::login($systemUser);

    $company = Company::factory()->create(['is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);

    // Hiring Requirement + Job Description: the job position itself, with a
    // real description and a Job Location (address_id, the field this
    // codebase already uses for "location" on a job -- confirmed via its
    // own migration comment "Job Location").
    $officeAddress = Partner::query()->create(['name' => 'Karachi Office', 'sub_type' => 'company', 'company_id' => $company->id]);
    $job = JobPosition::query()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'address_id'    => $officeAddress->id,
        'name'          => 'Senior Logistics Coordinator',
        'description'   => 'Own the daily dispatch schedule and driver coordination.',
        'requirements'  => '3+ years logistics experience.',
        'is_active'     => true,
    ]);

    $recruiter = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $interviewer = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $source = UTMSource::query()->firstOrCreate(['name' => 'LinkedIn']);
    $medium = UTMMedium::query()->firstOrCreate(['name' => 'Organic']);

    return compact('company', 'department', 'officeAddress', 'job', 'recruiter', 'interviewer', 'source', 'medium');
}

// ---------------------------------------------------------------------
// Stage pipeline provisioning.
// ---------------------------------------------------------------------
it('provisions the named Screening/Interview/Assessment/Offer/Hired pipeline, idempotently, without touching existing stages', function () {
    $existingCount = Stage::query()->count();

    app(RecruitmentWorkflowSeeder::class)->run();
    app(RecruitmentWorkflowSeeder::class)->run();

    $names = Stage::query()->whereIn('name', ['Screening', 'Interview', 'Assessment', 'Offer', 'Hired'])->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Assessment', 'Hired', 'Interview', 'Offer', 'Screening']);

    $hired = Stage::query()->where('name', 'Hired')->first();
    expect($hired->hired_stage)->toBeTrue();

    // Running twice must not duplicate rows.
    expect(Stage::query()->whereIn('name', ['Screening', 'Interview', 'Assessment', 'Offer', 'Hired'])->count())->toBe(5)
        ->and(Stage::query()->count())->toBe($existingCount + 5);
});

// ---------------------------------------------------------------------
// Full pipeline: candidate pool -> screening -> interview -> assessment ->
// offer -> hired, preserving candidate/application/job/company/department/
// location/source/interviewers/hiring owner/scores throughout.
// ---------------------------------------------------------------------
it('carries a candidate through the full pipeline preserving every required field', function () {
    $f = recruitmentFixture();
    app(RecruitmentWorkflowSeeder::class)->run();
    $screening = Stage::query()->where('name', 'Screening')->whereNull('company_id')->firstOrFail();
    $interview = Stage::query()->where('name', 'Interview')->whereNull('company_id')->firstOrFail();
    $assessment = Stage::query()->where('name', 'Assessment')->whereNull('company_id')->firstOrFail();
    $offer = Stage::query()->where('name', 'Offer')->whereNull('company_id')->firstOrFail();

    // Candidate Pool: candidate record with resume/profile links preserved.
    $candidate = Candidate::query()->create([
        'company_id'       => $f['company']->id,
        'name'             => 'Ayesha Khan',
        'email_from'       => 'ayesha.khan@example.test',
        'phone'            => '+92 300 1234567',
        'linkedin_profile' => 'https://linkedin.com/in/ayesha-khan',
        'resume_path'      => 'candidates/resumes/ayesha-khan.pdf',
        'source_reference' => 'Referred by internal staff',
        'is_active'        => true,
    ]);
    expect($candidate->partner_id)->not->toBeNull(); // auto-created, required for conversion later

    $application = Applicant::query()->create([
        'company_id'    => $f['company']->id,
        'candidate_id'  => $candidate->id,
        'job_id'        => $f['job']->id,
        'department_id' => $f['department']->id,
        'source_id'     => $f['source']->id,
        'medium_id'     => $f['medium']->id,
        'recruiter_id'  => $f['recruiter']->id,
        'stage_id'      => $screening->id,
        'create_date'   => now(),
    ]);
    $application->interviewer()->sync([$f['interviewer']->id]);

    expect($application->job_id)->toBe($f['job']->id)
        ->and($application->department_id)->toBe($f['department']->id)
        ->and($application->company_id)->toBe($f['company']->id)
        ->and($application->source_id)->toBe($f['source']->id)
        ->and($application->recruiter_id)->toBe($f['recruiter']->id)
        ->and($application->interviewer->pluck('id')->all())->toBe([$f['interviewer']->id])
        ->and($application->job->address_id)->toBe($f['officeAddress']->id) // location preserved
        ->and($application->job->description)->not->toBeEmpty(); // job description preserved

    // Screening.
    $application->update(['screening_score' => 8.5, 'stage_id' => $interview->id]);
    // Interview.
    $application->update(['interview_score' => 9.0, 'stage_id' => $assessment->id]);
    // Assessment (explicitly "where configured" -- exercised here, but the
    // pipeline works identically if a job's stages skip it entirely, since
    // stage transitions are just stage_id updates, not a hardcoded sequence).
    $application->update(['assessment_score' => 7.5, 'stage_id' => $offer->id]);
    // Offer.
    $application->update(['offer_status' => 'sent', 'offer_date' => now(), 'salary_proposed' => 250000]);

    $application->refresh();
    expect((float) $application->screening_score)->toBe(8.5)
        ->and((float) $application->interview_score)->toBe(9.0)
        ->and((float) $application->assessment_score)->toBe(7.5)
        ->and($application->offer_status)->toBe('sent');

    // Application history: every one of those updates is captured in the
    // existing chatter/activity-log audit trail (HasLogActivity), not a new
    // mechanism.
    expect($application->messages()->count())->toBeGreaterThan(0);

    // Hired -> Employee Conversion.
    $employee = app(CandidateConversionService::class)->convert($application);

    expect($employee)->not->toBeNull()
        ->and($employee->name)->toBe('Ayesha Khan')
        ->and($employee->job_id)->toBe($f['job']->id)
        ->and($employee->department_id)->toBe($f['department']->id)
        ->and($employee->company_id)->toBe($f['company']->id)
        ->and($employee->partner_id)->toBe($candidate->partner_id)
        ->and($employee->work_email)->toBe('ayesha.khan@example.test')
        ->and($employee->mobile_phone)->toBe('+92 300 1234567');

    $candidate->refresh();
    $application->refresh();
    expect($candidate->employee_id)->toBe($employee->id) // recruitment-to-employee relationship preserved
        ->and($application->application_status)->toBe(ApplicationStatus::HIRED)
        // documents/profile links preserved on the candidate throughout, not
        // dropped or overwritten by the conversion.
        ->and($candidate->resume_path)->toBe('candidates/resumes/ayesha-khan.pdf')
        ->and($candidate->linkedin_profile)->toBe('https://linkedin.com/in/ayesha-khan')
        ->and($candidate->source_reference)->toBe('Referred by internal staff');
});

// ---------------------------------------------------------------------
// Job Posting is a distinct stage from Job Description: the same
// JobPosition record carries a posting_status/published_at/posting_channels
// lifecycle (Draft -> Published -> Paused/Closed) on top of its
// name/description/requirements/location.
// ---------------------------------------------------------------------
it('tracks Job Posting as a distinct lifecycle from the Job Description, with channels', function () {
    // The Filament form's posting_status field is ->required()->default('draft'),
    // so a real submission always sends an explicit value; passed explicitly
    // here too rather than relying on the DB column default (which a plain
    // Eloquent create() does not read back onto the in-memory instance).
    $f = recruitmentFixture();
    $f['job']->update(['posting_status' => 'draft']);

    expect($f['job']->posting_status)->toBe('draft')
        ->and($f['job']->published_at)->toBeNull();

    $f['job']->update([
        'posting_status'   => 'published',
        'published_at'     => now(),
        'posting_channels' => ['company_website', 'linkedin'],
    ]);
    $f['job']->refresh();

    expect($f['job']->posting_status)->toBe('published')
        ->and($f['job']->published_at)->not->toBeNull()
        ->and($f['job']->posting_channels)->toBe(['company_website', 'linkedin'])
        // Job Description fields untouched by the posting lifecycle change.
        ->and($f['job']->description)->toBe('Own the daily dispatch schedule and driver coordination.')
        ->and($f['job']->address_id)->toBe($f['officeAddress']->id);

    $f['job']->update(['posting_status' => 'closed']);
    expect($f['job']->fresh()->posting_status)->toBe('closed');
});

// ---------------------------------------------------------------------
// Duplicate conversion is prevented; re-converting returns the same employee.
// ---------------------------------------------------------------------
it('prevents duplicate employee conversion: converting twice returns the same employee, creates no second one', function () {
    $f = recruitmentFixture();
    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Bilal Ahmed', 'email_from' => 'bilal@example.test', 'is_active' => true]);
    $application = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'department_id' => $f['department']->id, 'create_date' => now()]);

    $service = app(CandidateConversionService::class);
    $employeeCountBefore = Employee::count();

    $first = $service->convert($application);
    $second = $service->convert($application->fresh());

    expect($first->id)->toBe($second->id)
        ->and(Employee::count())->toBe($employeeCountBefore + 1);
});

it('the createEmployee() UI entry point is also idempotent through the same guard', function () {
    $f = recruitmentFixture();
    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Sara Malik', 'email_from' => 'sara@example.test', 'is_active' => true]);
    $application = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'department_id' => $f['department']->id, 'create_date' => now()]);

    $employee1 = $application->createEmployee();
    $employee2 = $application->fresh()->createEmployee();

    expect($employee1->id)->toBe($employee2->id);
});

// ---------------------------------------------------------------------
// Cross-company guards.
// ---------------------------------------------------------------------
it('refuses conversion when the candidate is already an employee in a different company', function () {
    $f = recruitmentFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherEmployee = Employee::query()->create(['company_id' => $otherCompany->id, 'name' => 'Existing Elsewhere', 'is_active' => true]);

    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Cross Company', 'email_from' => 'cross@example.test', 'is_active' => true, 'employee_id' => $otherEmployee->id]);
    $application = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'create_date' => now()]);

    expect(fn () => app(CandidateConversionService::class)->convert($application))
        ->toThrow(RuntimeException::class, 'another company');
});

it('refuses conversion when the application\'s department does not belong to the application\'s company', function () {
    $f = recruitmentFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherDepartment = Department::factory()->create(['company_id' => $otherCompany->id, 'manager_id' => null]);

    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Dept Mismatch', 'email_from' => 'dept@example.test', 'is_active' => true]);
    $application = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'department_id' => $otherDepartment->id, 'create_date' => now()]);

    expect(fn () => app(CandidateConversionService::class)->convert($application))
        ->toThrow(RuntimeException::class, 'department does not belong');
});

// ---------------------------------------------------------------------
// Rejected outcome: no employee is ever created.
// ---------------------------------------------------------------------
it('a rejected application never produces an employee, and the reason is preserved', function () {
    $f = recruitmentFixture();
    $refuseReason = RefuseReason::query()->create(['name' => 'Not a technical fit']);
    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Not Selected', 'email_from' => 'notselected@example.test', 'is_active' => true]);
    $application = Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'create_date' => now()]);

    $application->setAsRefused($refuseReason->id);
    $application->refresh();

    expect($application->application_status)->toBe(ApplicationStatus::REFUSED)
        ->and($application->refuse_reason_id)->toBe($refuseReason->id)
        ->and($candidate->fresh()->employee_id)->toBeNull();
});

// ---------------------------------------------------------------------
// Company isolation for the global stage pipeline.
// ---------------------------------------------------------------------
it('the global (company_id null) stage pipeline is usable by every company, not duplicated per company', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);

    app(RecruitmentWorkflowSeeder::class)->run();

    $screeningStages = Stage::query()->where('name', 'Screening')->get();
    expect($screeningStages)->toHaveCount(1)
        ->and($screeningStages->first()->company_id)->toBeNull();
});
