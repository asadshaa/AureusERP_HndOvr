<?php

/**
 * Regression tests for the recruitment audit fixes (November 2026):
 * re-imports never move an application back or change its creator, intake
 * checks rights, stages come from the application's company, the seeder is
 * non-destructive, the Blocked view works, the candidate's contact fields on
 * the application form are saved, job dates are validated, emails escape
 * what the public sent, refuse reasons and recruitment records stay in their
 * company, the legacy salary fields follow the offer salary rule, and the
 * candidate email is required with a soft duplicate warning.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Seeders\StageSeeder;
use Webkul\Recruitment\Enums\RecruitmentState;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\EditApplicant;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ViewApplicant;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\CreateCandidate;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobByPositionResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\EditJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\RefuseReasonResource;
use Webkul\Recruitment\Jobs\SendRecruitmentMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Policies\ApplicantPolicy;
use Webkul\Recruitment\Policies\CandidatePolicy;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

/** Every applicant / candidate permission, for a recruiter of $company. */
function auditRecruiter(Company $company): User
{
    return jobOpeningUser(
        $company,
        'view_any_recruitment_applicant', 'view_recruitment_applicant', 'create_recruitment_applicant', 'update_recruitment_applicant', 'delete_recruitment_applicant',
        'view_any_recruitment_candidate', 'view_recruitment_candidate', 'create_recruitment_candidate', 'update_recruitment_candidate',
    );
}

/** @return array{company: Company, recruiter: User, job: JobPosition, candidate: Candidate, applicant: Applicant} */
function auditFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $recruiter = auditRecruiter($company);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Customs Officer', 'is_active' => true]);
    $partner = Partner::query()->create(['name' => 'Sana Malik', 'sub_type' => 'individual', 'company_id' => $company->id]);
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Sana Malik', 'email_from' => 'sana.malik@example.test', 'partner_id' => $partner->id]);
    $applicant = Applicant::query()->create(['company_id' => $company->id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'stage_id' => Stage::defaultFor($company->id)?->id, 'create_date' => now()]);

    return compact('company', 'recruiter', 'job', 'candidate', 'applicant');
}

function auditActAs(User $user): void
{
    test()->actingAs($user);
    CurrentCompany::flush();
}

// ---------------------------------------------------------------------
// Intake re-import and authorization
// ---------------------------------------------------------------------

it('never moves a re-imported application back to the first stage or changes its creator', function () {
    $f = auditFixture();
    $intake = app(ApplicantIntakeService::class);
    $payload = ['external_application_id' => 'ROZ-77', 'candidate_name' => 'Hamza Ali', 'candidate_email' => 'hamza.ali@example.test', 'job_id' => $f['job']->id, 'source_details' => 'Rozee export'];

    $first = $intake->import('manual', $payload, $f['recruiter']);
    $later = Stage::query()->availableTo($f['company']->id)->where('id', '!=', $first->stage_id)->orderByDesc('sort')->firstOrFail();
    $first->update(['stage_id' => $later->id]);

    $again = $intake->import('manual', [...$payload, 'candidate_phone' => '03001234567'], auditRecruiter($f['company']));

    expect($again->id)->toBe($first->id)
        ->and($again->stage_id)->toBe($later->id)
        ->and($again->creator_id)->toBe($f['recruiter']->id)
        ->and($again->candidate->phone)->toBe('03001234567');
});

it('refuses intake from a user who may not create applications', function () {
    $f = auditFixture();
    $payload = ['external_application_id' => 'ROZ-78', 'candidate_name' => 'Hira', 'candidate_email' => 'hira@example.test', 'job_id' => $f['job']->id, 'source_details' => 'Export'];

    expect(fn () => app(ApplicantIntakeService::class)->import('manual', $payload, jobOpeningUser($f['company'])))
        ->toThrow(RuntimeException::class, 'not allowed')
        ->and(Applicant::query()->where('external_application_id', 'ROZ-78')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// Stages
// ---------------------------------------------------------------------

it('starts and reopens applications in their own company\'s default stage', function () {
    $f = auditFixture();
    $other = Company::factory()->create(['is_active' => true]);
    $foreignDefault = Stage::query()->create(['company_id' => $other->id, 'name' => 'Other intake', 'is_default' => true, 'sort' => -10]);
    $ownDefault = Stage::defaultFor($f['company']->id);

    expect($ownDefault)->not->toBeNull()
        ->and($ownDefault->id)->not->toBe($foreignDefault->id)
        ->and(Stage::defaultFor($other->id)->id)->toBe($foreignDefault->id);

    $reason = RefuseReason::query()->create(['name' => 'Not now', 'template' => 'applicant-refuse', 'company_id' => null]);
    $f['applicant']->setAsRefused($reason->id);
    $f['applicant']->refresh()->reopen();

    expect($f['applicant']->refresh()->stage_id)->toBe($ownDefault->id);

    // "New applications" on the jobs board count the company's default stage, not stage id 1.
    auditActAs($f['recruiter']);
    expect(JobByPositionResource::newApplicationsStageId())->toBe($ownDefault->id);
});

it('re-runs the stage seeder without deleting or duplicating stages', function () {
    $f = auditFixture();
    $own = Stage::query()->create(['company_id' => $f['company']->id, 'name' => 'Company screening', 'sort' => 50]);
    $sharedDefaults = Stage::query()->whereNull('company_id')->where('is_default', true)->count();
    Auth::logout();

    app(StageSeeder::class)->run();
    $afterFirstRun = Stage::query()->count();
    app(StageSeeder::class)->run();

    expect(Stage::query()->count())->toBe($afterFirstRun)
        ->and(Stage::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(Stage::query()->whereKey($f['applicant']->stage_id)->exists())->toBeTrue()
        ->and(Stage::query()->whereNull('company_id')->where('is_default', true)->count())->toBe(max(1, $sharedDefaults))
        ->and(Stage::query()->whereNull('company_id')->where('name', 'Contract Signed')->count())->toBe(1);
});

// ---------------------------------------------------------------------
// Applications list and form
// ---------------------------------------------------------------------

it('lists blocked applications in the Blocked view and hides them elsewhere', function () {
    $f = auditFixture();
    $f['applicant']->update(['state' => RecruitmentState::BLOCKED->value]);
    auditActAs($f['recruiter']);

    Livewire::test(ListApplicants::class)
        ->set('activeTableView', 'blocked')
        ->assertCanSeeTableRecords([$f['applicant']]);

    Livewire::test(ListApplicants::class)
        ->set('activeTableView', 'in_progress')
        ->assertCanNotSeeTableRecords([$f['applicant']]);
});

it('saves the candidate\'s email, phone and LinkedIn edited on the application form', function () {
    $f = auditFixture();
    auditActAs($f['recruiter']);

    Livewire::test(EditApplicant::class, ['record' => $f['applicant']->getKey()])
        ->fillForm([
            'candidate_email_from'       => 'sana.new@example.test',
            'candidate_phone'            => '03219876543',
            'candidate_linkedin_profile' => 'https://www.linkedin.com/in/sana-malik',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($f['candidate']->refresh()->email_from)->toBe('sana.new@example.test')
        ->and($f['candidate']->phone)->toBe('03219876543')
        ->and($f['candidate']->linkedin_profile)->toBe('https://www.linkedin.com/in/sana-malik');
});

it('refuses a job whose end date is before its start date', function () {
    $f = auditFixture();
    auditActAs(jobOpeningUser($f['company'], 'view_any_recruitment_job::position', 'view_recruitment_job::position', 'update_recruitment_job::position'));

    Livewire::test(EditJobPosition::class, ['record' => $f['job']->getKey()])
        ->fillForm(['date_from' => '2026-12-10', 'date_to' => '2026-12-01'])
        ->call('save')
        ->assertHasFormErrors(['date_to']);

    expect($f['job']->refresh()->date_to)->toBeNull();
});

// ---------------------------------------------------------------------
// Emails
// ---------------------------------------------------------------------

it('escapes the candidate name, job and company in recruitment emails', function () {
    $from = ['name' => 'HR <b>Team</b>', 'address' => 'hr@example.test', 'company' => ['name' => 'Acme <i>Co</i>', 'phone' => '1', 'email' => 'a@example.test', 'website' => 'https://example.test']];

    $refuse = view('recruitments::mails.applicant-refuse', ['payload' => ['applicant_name' => '<script>alert(1)</script>', 'from' => $from]])->render();
    $notInterested = view('recruitments::mails.applicant-not-interested', ['payload' => ['applicant_name' => '<script>x</script>', 'from' => $from]])->render();
    $confirm = view('recruitments::mails.application-confirm', ['payload' => ['record_name' => '<img src=x>', 'job_position' => '<script>job</script>', 'from' => $from]])->render();

    foreach ([$refuse, $notInterested, $confirm] as $html) {
        expect($html)->not->toContain('<script>')
            ->and($html)->not->toContain('<i>Co</i>')
            ->and($html)->toContain('Acme &lt;i&gt;Co&lt;/i&gt;');
    }

    expect($refuse)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($refuse)->not->toContain('<b>Team</b>')
        ->and($confirm)->toContain('&lt;script&gt;job&lt;/script&gt;')
        ->and($confirm)->not->toContain('<img src=x>');
});

// ---------------------------------------------------------------------
// Refuse reasons
// ---------------------------------------------------------------------

it('offers only shared and own-company refuse reasons and validates the email recipient', function () {
    $f = auditFixture();
    $other = Company::factory()->create(['is_active' => true]);
    $shared = RefuseReason::query()->create(['name' => 'Shared reason', 'template' => 'applicant-refuse', 'company_id' => null]);
    $foreign = RefuseReason::query()->create(['name' => 'Other company reason', 'template' => 'applicant-refuse', 'company_id' => $other->id]);
    auditActAs($f['recruiter']);
    $own = RefuseReason::query()->create(['name' => 'Own reason', 'template' => 'applicant-refuse']);

    expect($own->company_id)->toBe($f['company']->id)
        ->and(RefuseReason::optionsFor($f['company']->id))->toHaveKeys([$shared->id, $own->id])
        ->and(RefuseReason::optionsFor($f['company']->id))->not->toHaveKey($foreign->id)
        ->and(RefuseReasonResource::getEloquentQuery()->pluck('id')->all())->not->toContain($foreign->id);

    // Another company's reason is refused, whatever is posted.
    Livewire::test(ViewApplicant::class, ['record' => $f['applicant']->getKey()])
        ->callAction('Refuse', data: ['refuse_reason_id' => $foreign->id, 'notify' => false])
        ->assertHasActionErrors(['refuse_reason_id']);

    // The recipient defaults to the candidate and must be a valid email.
    Livewire::test(ViewApplicant::class, ['record' => $f['applicant']->getKey()])
        ->mountAction('Refuse')
        ->setActionData(['refuse_reason_id' => $own->id, 'notify' => true])
        ->assertActionDataSet(['email' => 'sana.malik@example.test'])
        ->setActionData(['email' => 'not-an-email'])
        ->callMountedAction()
        ->assertHasActionErrors(['email']);

    expect($f['applicant']->refresh()->refuse_reason_id)->toBeNull();

    Livewire::test(ViewApplicant::class, ['record' => $f['applicant']->getKey()])
        ->callAction('Refuse', data: ['refuse_reason_id' => $own->id, 'notify' => true, 'email' => 'sana.malik@example.test'])
        ->assertHasNoActionErrors();

    expect($f['applicant']->refresh()->refuse_reason_id)->toBe($own->id);
    Queue::assertPushed(SendRecruitmentMail::class, fn (SendRecruitmentMail $job): bool => $job->payload['to']['address'] === 'sana.malik@example.test');
});

// ---------------------------------------------------------------------
// Company isolation of applicants and candidates
// ---------------------------------------------------------------------

it('keeps applicants and candidates of one company away from another company\'s recruiters', function () {
    $f = auditFixture();
    $other = Company::factory()->create(['is_active' => true]);
    $outsider = auditRecruiter($other);
    $applicants = app(ApplicantPolicy::class);
    $candidates = app(CandidatePolicy::class);

    expect($applicants->view($f['recruiter'], $f['applicant']))->toBeTrue()
        ->and($applicants->update($f['recruiter'], $f['applicant']))->toBeTrue()
        ->and($candidates->view($f['recruiter'], $f['candidate']))->toBeTrue()
        ->and($applicants->view($outsider, $f['applicant']))->toBeFalse()
        ->and($applicants->update($outsider, $f['applicant']))->toBeFalse()
        ->and($applicants->delete($outsider, $f['applicant']))->toBeFalse()
        ->and($candidates->view($outsider, $f['candidate']))->toBeFalse()
        ->and($candidates->update($outsider, $f['candidate']))->toBeFalse();

    // A member of both companies sees both, but changes only the active company's records.
    $outsider->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);
    CurrentCompany::flush();
    expect($applicants->view($outsider->refresh(), $f['applicant']))->toBeTrue()
        ->and($applicants->update($outsider, $f['applicant']))->toBeFalse();

    $this->actingAs(auditRecruiter($other))->get(ApplicantResource::getUrl('view', ['record' => $f['applicant']]))->assertNotFound();
});

// ---------------------------------------------------------------------
// Legacy salary and offer fields
// ---------------------------------------------------------------------

it('shows the application\'s salary fields only to those who may see offer salaries, and the offer fields read-only', function () {
    $f = auditFixture();
    $f['applicant']->update(['salary_expected' => 175000, 'offer_status' => 'accepted']);

    auditActAs($f['recruiter']);
    expect(ApplicantResource::canSeeSalary($f['applicant']))->toBeFalse();
    Livewire::test(ViewApplicant::class, ['record' => $f['applicant']->getKey()])->assertDontSee('Expected Salary');

    Livewire::test(EditApplicant::class, ['record' => $f['applicant']->getKey()])
        ->assertFormFieldIsDisabled('offer_status')
        ->assertFormFieldIsDisabled('offer_date')
        ->fillForm(['offer_status' => 'declined'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($f['applicant']->refresh()->offer_status)->toBe('accepted');

    $hr = jobOpeningRoleUser($f['company'], 'hr_manager');
    auditActAs($hr);
    expect(ApplicantResource::canSeeSalary($f['applicant']))->toBeTrue();
    Livewire::test(ViewApplicant::class, ['record' => $f['applicant']->getKey()])->assertSee('Expected Salary');
});

// ---------------------------------------------------------------------
// Candidate email
// ---------------------------------------------------------------------

it('requires the candidate email and warns, without blocking, about a duplicate in the company', function () {
    $f = auditFixture();
    auditActAs($f['recruiter']);

    Livewire::test(CreateCandidate::class)
        ->fillForm(['name' => 'No Email', 'email_from' => null])
        ->call('create')
        ->assertHasFormErrors(['email_from' => 'required']);

    expect(Candidate::sameEmailInCompany(' SANA.MALIK@example.test ', $f['company']->id)->pluck('id')->all())->toBe([$f['candidate']->id])
        ->and(Candidate::sameEmailInCompany('sana.malik@example.test', $f['company']->id, $f['candidate']->id)->exists())->toBeFalse()
        ->and(Candidate::sameEmailInCompany('sana.malik@example.test', Company::factory()->create()->id)->exists())->toBeFalse();

    Livewire::test(CreateCandidate::class)
        ->fillForm(['name' => 'Sana M.', 'email_from' => 'sana.malik@example.test'])
        ->assertSee('Also used by Sana Malik')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Candidate::query()->where('company_id', $f['company']->id)->where('email_from', 'sana.malik@example.test')->count())->toBe(2);
});
