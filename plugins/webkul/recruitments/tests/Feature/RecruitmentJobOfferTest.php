<?php

/**
 * Job offers (HR parity 3.6): an offer on an application is approved by the
 * hiring manager, then HR, then the CEO only when the monthly gross exceeds
 * the company threshold; approvers can return it for changes and the
 * requester resubmits it into the same approval; the approved offer becomes
 * a branded PDF, is emailed (queued) with the letter and a signed accept /
 * decline link; the candidate's answer moves the application, HR creates the
 * employee with the agreed terms (onboarding starts), a daily command
 * expires unanswered offers once, and offers and salaries stay with the
 * people allowed to see them.
 */

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Seeders\JobOfferDefaultsSeeder;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ViewApplicant;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\RelationManagers\OffersRelationManager;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages\ListJobOffers;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages\ViewJobOffer;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\OfferLetterTemplateResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\OfferLetterTemplateResource\Pages\ManageOfferLetterTemplates;
use Webkul\Recruitment\Jobs\SendRecruitmentMail;
use Webkul\Recruitment\Mail\JobOfferMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Models\OfferSetting;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Policies\JobOfferPolicy;
use Webkul\Recruitment\Policies\OfferLetterTemplatePolicy;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    ApprovalEngine::flushMemo();
});

/**
 * A company (with its offer workflow and default template, from the
 * Company::created hook), HR (two people, so one can approve what the other
 * submitted), a recruiter, the job's hiring manager, the CEO approver and an
 * application.
 *
 * @return array{company: Company, hr: User, hr2: User, recruiter: User, manager: User, managerEmployee: Employee, ceo: User, job: JobPosition, department: Department, applicant: Applicant}
 */
function offerFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));

    $company = Company::factory()->create(['is_active' => true, 'email' => 'careers@'.Str::lower(Str::random(6)).'.example', 'currency_id' => DB::table('currencies')->where('name', 'PKR')->value('id')]);
    $hr = offerRoleUser($company, 'hr_manager');
    $hr2 = offerRoleUser($company, 'hr_manager');

    // HR staff are employees too: the engine never lets the employee a request is about decide it.
    foreach ([$hr, $hr2] as $index => $person) {
        Employee::query()->create(['company_id' => $company->id, 'user_id' => $person->id, 'name' => 'HR '.($index + 1), 'is_active' => true, 'employment_status' => 'active']);
    }
    $recruiter = offerRoleUser($company, 'recruiter');
    $manager = jobOpeningUser($company);
    $managerEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'name' => 'Kamran Shah', 'is_active' => true, 'employment_status' => 'active']);
    $ceo = jobOpeningUser($company);

    // The CEO step of this company's offer workflow goes to the test CEO.
    ApprovalStep::query()
        ->whereIn('workflow_id', ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', JobOffer::APPROVAL_REQUEST_TYPE)->pluck('id'))
        ->where('sequence', 3)
        ->update(['approver_user_id' => $ceo->id, 'approver_role_id' => null, 'hierarchy_route' => null]);

    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Customs']);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Customs Clearance Officer', 'is_active' => true, 'manager_id' => $managerEmployee->id, 'recruiter_id' => $recruiter->id, 'department_id' => $department->id]);

    return [
        'company'         => $company,
        'hr'              => $hr,
        'hr2'             => $hr2,
        'recruiter'       => $recruiter,
        'manager'         => $manager,
        'managerEmployee' => $managerEmployee,
        'ceo'             => $ceo,
        'job'             => $job,
        'department'      => $department,
        'applicant'       => offerApplicant($job, 'Ayesha Khan', 'ayesha.khan@example.com', $recruiter),
    ];
}

/**
 * A user of $company with a seeded HR role. The roles are already in the
 * (copied) test database, so the role seeder only runs when one is missing:
 * fewer writes to the shared role tables while other suites run.
 */
function offerRoleUser(Company $company, string $roleName): User
{
    $role = Role::query()->whereRaw('LOWER(name) = ?', [$roleName])->first();

    if (! $role) {
        return jobOpeningRoleUser($company, $roleName);
    }

    $user = jobOpeningUser($company);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function offerApplicant(JobPosition $job, string $name, ?string $email, ?User $recruiter = null): Applicant
{
    $partner = Partner::query()->create(['name' => $name, 'sub_type' => 'individual', 'company_id' => $job->company_id]);
    $candidate = Candidate::query()->create(['company_id' => $job->company_id, 'name' => $name, 'email_from' => $email, 'partner_id' => $partner->id]);

    return Applicant::query()->create(['company_id' => $job->company_id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'recruiter_id' => $recruiter?->id, 'create_date' => now()]);
}

/** @return array<string, mixed> */
function offerData(array $f, array $overrides = []): array
{
    $today = now(OfferLetterRenderer::timezone());

    return array_replace_recursive([
        'job_id'               => $f['job']->id,
        'designation'          => 'Senior Customs Clearance Officer',
        'department_id'        => $f['department']->id,
        'reporting_manager_id' => $f['managerEmployee']->id,
        'joining_date'         => $today->copy()->addDays(30)->toDateString(),
        'expiry_date'          => $today->copy()->addDays(7)->toDateString(),
        'salary_gross'         => 250000,
        'salary_breakdown'     => ['basic' => 150000, 'house_rent' => 67500, 'utilities' => 15000, 'allowances' => 17500],
        'probation_months'     => 3,
        'working_hours'        => 'Monday to Saturday, 9:00 AM to 6:00 PM',
        'benefits'             => "Medical cover for self and family\nProvident fund",
    ], $overrides);
}

function draftOffer(array $f, array $overrides = [], ?User $by = null): JobOffer
{
    return app(JobOfferService::class)->createDraft($f['applicant'], offerData($f, $overrides), $by ?? $f['hr']);
}

/** Drafted by HR, approved by the hiring manager and the other HR person. */
function approvedOffer(array $f, array $overrides = []): JobOffer
{
    $service = app(JobOfferService::class);
    $offer = $service->submit(draftOffer($f, $overrides), $f['hr']);
    $service->approve($offer, $f['manager']);
    $service->approve($offer->refresh(), $f['hr2']);

    return $offer->refresh();
}

function sentOffer(array $f, array $overrides = []): JobOffer
{
    return app(JobOfferService::class)->send(approvedOffer($f, $overrides), $f['hr']);
}

function offerMailJob(): ?SendRecruitmentMail
{
    return Queue::pushed(SendRecruitmentMail::class, fn (SendRecruitmentMail $job): bool => $job->mailClass === JobOfferMail::class)->last();
}

// ---------------------------------------------------------------------
// Approval routing
// ---------------------------------------------------------------------

it('routes an offer to the hiring manager then HR, and skips the CEO at or below the threshold', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);
    $engine = app(ApprovalEngine::class);

    // Exactly the default threshold (300,000) does not exceed it.
    $offer = $service->submit(draftOffer($f, ['salary_gross' => 300000, 'salary_breakdown' => ['basic' => 180000, 'house_rent' => 81000, 'utilities' => 20000, 'allowances' => 19000]]), $f['hr']);
    $approval = $offer->approvalRequest;

    expect($offer->status)->toBe(JobOfferStatus::PENDING_APPROVAL)
        ->and($offer->requires_ceo_approval)->toBeFalse()
        ->and($offer->hiring_manager_id)->toBe($f['manager']->id)
        ->and($approval->request_type)->toBe('job_offer')
        ->and($approval->amount)->toBeNull()
        ->and(json_encode($approval->context))->not->toContain('300000')
        ->and($engine->resolveAuthorizedApprovers($approval)->pluck('id')->all())->toBe([$f['manager']->id])
        ->and($service->canDecide($f['hr2'], $offer))->toBeFalse()
        ->and($service->canDecide($f['manager'], $offer))->toBeTrue();

    // The hiring manager is told, with a link to the offer.
    expect(DB::table('notifications')->where('notifiable_id', $f['manager']->id)->where('data', 'like', '%job_offer_awaiting_%')->count())->toBe(1);

    $offer = $service->approve($offer, $f['manager'], 'Good fit');
    expect($offer->status)->toBe(JobOfferStatus::PENDING_APPROVAL)
        ->and($offer->approvalRequest->refresh()->current_step_sequence)->toBe(2)
        // HR, but not the HR person who submitted it.
        ->and($service->canDecide($f['hr'], $offer))->toBeFalse()
        ->and($service->canDecide($f['hr2'], $offer))->toBeTrue();

    $offer = $service->approve($offer, $f['hr2']);

    expect($offer->status)->toBe(JobOfferStatus::APPROVED)
        ->and($offer->approved_at)->not->toBeNull()
        ->and($offer->approvalRequest->refresh()->status)->toBe('approved')
        ->and($offer->letter_path)->not->toBeNull()
        ->and($offer->events()->pluck('event')->all())->toBe(['created', 'submitted', 'step_approved', 'approved', 'letter_generated']);
});

it('adds the CEO step only when the salary exceeds the company threshold', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);

    $offer = approvedOffer($f, ['salary_gross' => 350000, 'salary_breakdown' => ['basic' => 210000, 'house_rent' => 94500, 'utilities' => 25000, 'allowances' => 20500]]);

    expect($offer->status)->toBe(JobOfferStatus::PENDING_APPROVAL)
        ->and($offer->requires_ceo_approval)->toBeTrue()
        ->and($offer->approvalRequest->current_step_sequence)->toBe(3)
        ->and(app(ApprovalEngine::class)->resolveAuthorizedApprovers($offer->approvalRequest)->pluck('id')->all())->toBe([$f['ceo']->id])
        ->and($service->canSeeSalary($f['ceo'], $offer))->toBeTrue();

    $offer = $service->approve($offer, $f['ceo']);
    expect($offer->status)->toBe(JobOfferStatus::APPROVED);

    // A company may raise its own threshold: the same salary then skips the CEO.
    $service->saveSettings($f['company']->id, '400000', $f['hr']);
    $service->withdraw($offer, $f['hr']);
    $second = approvedOffer($f, ['salary_gross' => 350000, 'salary_breakdown' => null]);

    expect($second->status)->toBe(JobOfferStatus::APPROVED)
        ->and($second->requires_ceo_approval)->toBeFalse()
        ->and(OfferSetting::ceoThresholdFor($f['company']->id))->toBe('400000.00');

    // Only HR of the company changes the threshold.
    expect(fn () => $service->saveSettings($f['company']->id, '1', $f['recruiter']))->toThrow(AuthorizationException::class);
});

it('falls back to the recruiter\'s line manager, then to HR, when the job has no manager', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);
    $f['job']->update(['manager_id' => null]);

    $lead = jobOpeningUser($f['company']);
    $leadEmployee = Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $lead->id, 'name' => 'Recruiting Lead', 'is_active' => true, 'employment_status' => 'active']);
    Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $f['recruiter']->id, 'name' => 'Recruiter', 'is_active' => true, 'employment_status' => 'active', 'parent_id' => $leadEmployee->id]);

    $offer = $service->submit(draftOffer($f), $f['hr']);
    expect(app(ApprovalEngine::class)->resolveAuthorizedApprovers($offer->approvalRequest)->pluck('id')->all())->toBe([$lead->id]);

    $service->withdraw($offer, $f['hr']);
    $f['applicant']->update(['recruiter_id' => null]);
    $f['job']->update(['recruiter_id' => null]);

    $offer = $service->submit(draftOffer($f), $f['hr']);
    expect($offer->hiring_manager_id)->toBeNull()
        ->and(app(ApprovalEngine::class)->resolveAuthorizedApprovers($offer->approvalRequest)->pluck('id')->all())->toContain($f['hr2']->id)
        ->and($service->canDecide($f['hr'], $offer))->toBeFalse()
        ->and($service->canDecide($f['hr2'], $offer))->toBeTrue();
});

it('returns an offer for changes and resubmits it into the same approval from the first step', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);

    $offer = $service->submit(draftOffer($f), $f['hr']);
    $approvalId = $offer->approval_request_id;

    $offer = $service->returnForChanges($offer, $f['manager'], 'Joining should be a week later');

    expect($offer->status)->toBe(JobOfferStatus::RETURNED)
        ->and($offer->approvalRequest->status)->toBe('returned')
        ->and(app(ApprovalEngine::class)->supportsReturn($offer->approvalRequest))->toBeTrue()
        ->and($offer->approvalSubjectUrl())->toBe(JobOfferResource::getUrl('view', ['record' => $offer]));

    // Only the requester resubmits; anyone who manages offers may edit it meanwhile.
    expect($service->canSubmit($f['hr2'], $offer))->toBeFalse()
        ->and($service->canSubmit($f['hr'], $offer))->toBeTrue();

    $offer = $service->update($offer, offerData($f, ['joining_date' => now(OfferLetterRenderer::timezone())->addDays(37)->toDateString()]), $f['hr2']);
    expect(fn () => $service->submit($offer, $f['hr2']))->toThrow(RuntimeException::class);

    $offer = $service->submit($offer->refresh(), $f['hr'], 'Moved the joining date');
    $approval = $offer->approvalRequest->refresh();

    expect($offer->status)->toBe(JobOfferStatus::PENDING_APPROVAL)
        ->and($offer->approval_request_id)->toBe($approvalId)
        ->and($approval->submission_round)->toBe(2)
        ->and($approval->current_step_sequence)->toBe(1)
        ->and($approval->decisions()->where('decision', 'resubmitted')->value('new_values'))->toContain('Joining date')
        ->and($offer->events()->pluck('event')->all())->toBe(['created', 'submitted', 'returned', 'updated', 'resubmitted']);

    // The manager decides the restarted step again.
    expect($service->approve($offer, $f['manager'])->approvalRequest->refresh()->current_step_sequence)->toBe(2);
});

it('withdraws an offer in approval (requester only) and an approved one, and keeps rejected offers closed', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);

    $offer = $service->submit(draftOffer($f), $f['hr']);
    expect($service->canWithdraw($f['hr2'], $offer))->toBeFalse()
        ->and(fn () => $service->withdraw($offer, $f['hr2']))->toThrow(RuntimeException::class);

    $offer = $service->withdraw($offer, $f['hr'], 'Position on hold');
    expect($offer->status)->toBe(JobOfferStatus::WITHDRAWN)
        ->and($offer->approvalRequest->status)->toBe('withdrawn')
        ->and($offer->withdraw_reason)->toBe('Position on hold');

    $rejected = $service->reject($service->submit(draftOffer($f), $f['hr']), $f['manager'], 'Budget not approved');
    expect($rejected->status)->toBe(JobOfferStatus::REJECTED)
        ->and($rejected->rejection_reason)->toBe('Budget not approved')
        ->and($service->canEdit($f['hr'], $rejected))->toBeFalse();

    $sent = sentOffer($f);
    $url = $service->respondUrl($sent);
    $sent = $service->withdraw($sent, $f['hr2'], 'Candidate found elsewhere');
    expect($sent->status)->toBe(JobOfferStatus::WITHDRAWN);

    // The candidate's link now only says the offer is no longer available.
    Auth::logout();
    $this->get($url)->assertStatus(410)->assertSee('no longer available')->assertDontSee('PKR');
    $this->post($url, ['decision' => 'accept'])->assertStatus(410);
    expect($sent->refresh()->status)->toBe(JobOfferStatus::WITHDRAWN);
});

it('refuses offers that are incomplete, inconsistent or duplicated', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);

    expect(fn () => draftOffer($f, ['salary_breakdown' => ['basic' => 1]]))->toThrow(ValidationException::class)
        ->and(fn () => draftOffer($f, ['expiry_date' => now()->addDays(60)->toDateString()]))->toThrow(ValidationException::class)
        ->and(fn () => draftOffer($f, ['joining_date' => now()->subDay()->toDateString()]))->toThrow(ValidationException::class);

    draftOffer($f);
    expect(fn () => draftOffer($f))->toThrow(RuntimeException::class, 'already has an open offer');
});

// ---------------------------------------------------------------------
// Letter and email
// ---------------------------------------------------------------------

it('generates the offer letter PDF from the company template with every placeholder filled', function () {
    $f = offerFixture();
    $offer = approvedOffer($f);
    $renderer = app(OfferLetterRenderer::class);

    expect(Storage::disk('local')->exists($offer->letter_path))->toBeTrue()
        ->and($offer->letter_path)->toStartWith('recruitments/offers/'.$f['company']->id.'/')
        ->and(Storage::disk('local')->get($offer->letter_path))->toStartWith('%PDF');

    $body = $renderer->renderBody($offer, $f['hr']);
    expect($body)->toContain('Ayesha Khan')
        ->toContain('Senior Customs Clearance Officer')
        ->toContain('PKR 250,000')
        ->toContain('House rent allowance: PKR 67,500')
        ->toContain($f['company']->name)
        ->toContain('Kamran Shah')
        ->not->toContain('{{');

    // A company template: merge tags from the editor and typed placeholders both work; markup is sanitised.
    $template = OfferLetterTemplate::query()->create([
        'company_id' => $f['company']->id,
        'name'       => 'Short',
        'body'       => '<p>Dear <span data-type="mergeTag" data-id="candidate_name"></span>, you join on {{ joining_date }} at {{salary}}.</p><script>alert(1)</script>',
        'is_default' => true,
    ]);
    $offer->update(['template_id' => $template->id]);

    $short = $renderer->renderBody($offer->refresh());
    expect($short)->toContain('Ayesha Khan')
        ->toContain('PKR 250,000')
        ->toContain(OfferLetterRenderer::date($offer->joining_date))
        ->not->toContain('<script')
        ->not->toContain('{{')
        ->and(OfferLetterTemplate::query()->where('company_id', $f['company']->id)->where('is_default', true)->count())->toBe(1);
});

it('emails the approved offer with the letter attached and a signed accept / decline link', function () {
    $f = offerFixture();
    $offer = sentOffer($f);
    $job = offerMailJob();

    expect($offer->status)->toBe(JobOfferStatus::SENT)
        ->and($offer->sent_to)->toBe('ayesha.khan@example.com')
        ->and($offer->response_token)->toHaveLength(48)
        ->and($job)->not->toBeNull()
        ->and($job->payload['to']['address'])->toBe('ayesha.khan@example.com')
        ->and($job->payload['from']['address'])->toBe($f['company']->email)
        ->and($job->payload['respond_url'])->toContain('/careers/offers/'.$offer->id.'/'.$offer->response_token)
        ->and($job->payload['respond_url'])->toContain('signature=')
        ->and($job->payload['subject'])->toBe('Offer of employment: Senior Customs Clearance Officer at '.$f['company']->name)
        ->and($job->attachments)->toHaveCount(1)
        ->and($job->attachments[0]['mime'])->toBe('application/pdf')
        ->and($job->attachments[0]['name'])->toBe('offer-letter-ayesha-khan.pdf')
        ->and(file_exists($job->attachments[0]['path']))->toBeTrue();

    // The email itself (sent by the queued job): letter attached, link in the body, salary not in it.
    Mail::fake();
    $job->handle();

    Mail::assertSent(JobOfferMail::class, function (JobOfferMail $mail) use ($offer): bool {
        $html = $mail->render();

        return $mail->hasTo('ayesha.khan@example.com')
            && count($mail->attachments()) === 1
            && str_contains($html, e($mail->payload['respond_url']))
            && str_contains($html, 'Ayesha Khan')
            && ! str_contains($html, '250,000')
            && str_contains($html, (string) $offer->reference);
    });

    // Sending moved the application to the offer stage of the pipeline (when the company has one).
    $offerStage = Stage::query()->whereNull('company_id')->where('name', 'Contract Proposal')->first();
    if ($offerStage) {
        expect($f['applicant']->refresh()->stage_id)->toBe($offerStage->id);
    }

    // A recruiter cannot see the salary, so cannot send; only an approved offer can be sent.
    $draft = draftOffer(['applicant' => offerApplicant($f['job'], 'Bilal Ahmed', 'bilal.ahmed@example.com')] + $f);

    expect(fn () => app(JobOfferService::class)->send($offer, $f['recruiter']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(JobOfferService::class)->send($draft, $f['hr']))->toThrow(RuntimeException::class, 'Only an approved offer');
});

// ---------------------------------------------------------------------
// Candidate's page
// ---------------------------------------------------------------------

it('shows the offer to the candidate and records the acceptance, moving the application to the hired stage', function () {
    $f = offerFixture();
    $offer = sentOffer($f);
    $url = app(JobOfferService::class)->respondUrl($offer);
    Auth::logout();

    $this->get($url)
        ->assertOk()
        ->assertSee('Ayesha Khan')
        ->assertSee('Senior Customs Clearance Officer')
        ->assertSee('PKR 250,000')
        ->assertSee('Accept offer');

    $this->post($url, ['decision' => 'accept', 'reason' => 'Looking forward to it'])->assertRedirect($url);

    $offer->refresh();
    $applicant = $f['applicant']->refresh()->load('stage');

    expect($offer->status)->toBe(JobOfferStatus::ACCEPTED)
        ->and($offer->responded_at)->not->toBeNull()
        ->and($offer->response_reason)->toBe('Looking forward to it')
        ->and($applicant->date_closed)->not->toBeNull()
        ->and($applicant->stage?->hired_stage)->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $f['recruiter']->id)->where('data', 'like', '%job_offer_accepted_'.$offer->id.'%')->count())->toBe(1);

    // The link now shows the answer; answering again changes nothing.
    $this->get($url)->assertOk()->assertSee('Offer accepted');
    $this->post($url, ['decision' => 'decline'])->assertOk();
    expect($offer->refresh()->status)->toBe(JobOfferStatus::ACCEPTED);
});

it('records a decline with the reason and tells the recruiter', function () {
    $f = offerFixture();
    $offer = sentOffer($f);
    $url = app(JobOfferService::class)->respondUrl($offer);
    Auth::logout();

    $this->post($url, ['decision' => 'decline', 'reason' => 'Accepted another offer'])->assertRedirect($url);

    expect($offer->refresh()->status)->toBe(JobOfferStatus::DECLINED)
        ->and($offer->response_reason)->toBe('Accepted another offer')
        ->and($f['applicant']->refresh()->date_closed)->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $f['recruiter']->id)->where('data', 'like', '%job_offer_declined_'.$offer->id.'%')->count())->toBe(1);

    $this->get($url)->assertOk()->assertSee('Offer declined');
});

it('refuses tampered or replaced links and shows an expired offer as expired', function () {
    $f = offerFixture();
    $offer = sentOffer($f);
    $service = app(JobOfferService::class);
    $url = $service->respondUrl($offer);
    Auth::logout();

    // Tampered: another token, another offer id or a broken signature.
    $this->get(str_replace($offer->response_token, Str::random(48), $url))->assertForbidden()->assertSee('not valid');
    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
    $this->post(str_replace('/offers/'.$offer->id.'/', '/offers/'.($offer->id + 1000).'/', $url), ['decision' => 'accept'])->assertForbidden();

    // Re-sending replaces the link: the old one stops working.
    Auth::login($f['hr']);
    $service->send($offer->refresh(), $f['hr']);
    Auth::logout();
    $this->get($url)->assertForbidden();
    $url = $service->respondUrl($offer->refresh());
    $this->get($url)->assertOk();

    // After the expiry date the link no longer accepts anything.
    $this->travelTo(now(OfferLetterRenderer::timezone())->addDays(8));
    $this->get($url)->assertStatus(410)->assertSee('expired');
    $this->post($url, ['decision' => 'accept'])->assertStatus(410);

    expect($offer->refresh()->status)->toBe(JobOfferStatus::SENT);
});

// ---------------------------------------------------------------------
// Hire and expiry
// ---------------------------------------------------------------------

it('creates the employee from the accepted offer with the agreed terms and starts onboarding', function () {
    $f = offerFixture();
    $offer = sentOffer($f);
    $service = app(JobOfferService::class);
    $service->respond($offer, $offer->response_token, true);
    $offer->refresh();

    expect($service->canCreateEmployee($f['recruiter'], $offer))->toBeFalse()
        ->and($service->employeeDefaults($offer, $f['recruiter']))->not->toHaveKey('base_salary')
        ->and($service->employeeDefaults($offer, $f['hr']))->toHaveKey('base_salary');

    Auth::login($f['hr']);
    $employee = $service->createEmployee($offer, $f['hr']);

    expect($employee->name)->toBe('Ayesha Khan')
        ->and($employee->company_id)->toBe($f['company']->id)
        ->and($employee->joining_date->toDateString())->toBe($offer->joining_date->toDateString())
        ->and((float) $employee->base_salary)->toBe(250000.0)
        ->and($employee->department_id)->toBe($f['department']->id)
        ->and($employee->parent_id)->toBe($f['managerEmployee']->id)
        ->and($employee->job_id)->toBe($f['job']->id)
        ->and($employee->job_title)->toBe('Senior Customs Clearance Officer')
        ->and($employee->probation_end_date->toDateString())->toBe($offer->joining_date->copy()->addMonthsNoOverflow(3)->subDay()->toDateString())
        ->and($offer->refresh()->employee_id)->toBe($employee->id)
        ->and($f['applicant']->candidate->refresh()->employee_id)->toBe($employee->id)
        ->and(EmployeeChecklist::query()->where('employee_id', $employee->id)->where('type', 'onboarding')->exists())->toBeTrue()
        ->and($offer->events()->where('event', 'employee_created')->exists())->toBeTrue();

    // The application's own "Create employee" button gets the same employee (no second one).
    expect($f['applicant']->refresh()->createEmployee()?->id)->toBe($employee->id)
        ->and($service->canCreateEmployee($f['hr'], $offer))->toBeFalse();
});

it('expires unanswered offers once and tells the recruiter', function () {
    $f = offerFixture();
    $offer = sentOffer($f);

    $this->travelTo(now(OfferLetterRenderer::timezone())->addDays(8));

    Artisan::call('recruitments:expire-offers');
    Artisan::call('recruitments:expire-offers');

    expect($offer->refresh()->status)->toBe(JobOfferStatus::EXPIRED)
        ->and($offer->expired_at)->not->toBeNull()
        ->and($offer->events()->where('event', 'expired')->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $f['recruiter']->id)->where('data', 'like', '%job_offer_expired_'.$offer->id.'%')->count())->toBe(1);

    $this->get(app(JobOfferService::class)->respondUrl($offer))->assertStatus(410)->assertSee('expired');
});

// ---------------------------------------------------------------------
// Company isolation and salary visibility
// ---------------------------------------------------------------------

it('keeps offers, their lists and their links inside their company', function () {
    $f = offerFixture();
    $offer = approvedOffer($f);
    $service = app(JobOfferService::class);

    $other = offerFixture();
    $otherOffer = draftOffer($other);

    // Another company's HR can neither see nor change this company's offer.
    expect($service->canView($other['hr'], $offer))->toBeFalse()
        ->and($service->canManage($other['hr'], $offer))->toBeFalse()
        ->and($service->canSeeSalary($other['hr'], $offer))->toBeFalse()
        ->and(app(JobOfferPolicy::class)->view($other['hr'], $offer))->toBeFalse()
        ->and(fn () => $service->send($offer, $other['hr']))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->withdraw($offer, $other['hr']))->toThrow(AuthorizationException::class);

    // Nor link the offer to its own department or manager.
    expect(fn () => $service->createDraft($other['applicant'], offerData($other, ['department_id' => $f['department']->id]), $other['hr']))->toThrow(ValidationException::class)
        ->and(fn () => $service->update($otherOffer, offerData($other, ['reporting_manager_id' => $f['managerEmployee']->id]), $other['hr']))->toThrow(ValidationException::class);

    test()->actingAs($f['hr']);
    CurrentCompany::flush();
    expect(JobOfferResource::getEloquentQuery()->pluck('id')->all())->toBe([$offer->id]);

    test()->actingAs($other['hr']);
    CurrentCompany::flush();
    expect(JobOfferResource::getEloquentQuery()->pluck('id')->all())->toBe([$otherOffer->id]);

    // HR's signed letter link still needs a user allowed to see the offer.
    $download = $service->letterDownloadUrl($offer);
    $this->actingAs($other['hr'])->get($download)->assertForbidden();
    $this->actingAs($f['recruiter'])->get($download)->assertForbidden();
    $this->actingAs($f['hr'])->get($download)->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('shows the salary only to HR, hiring managers and the offer\'s approvers', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);
    $employee = jobOpeningUser($f['company'], 'view_any_recruitment_applicant', 'view_recruitment_applicant');

    // A recruiter drafts the offer but cannot set or see the salary.
    $offer = $service->createDraft($f['applicant'], offerData($f), $f['recruiter']);
    expect($offer->salary_gross)->toBeNull()
        ->and($service->canSeeSalary($f['recruiter'], $offer))->toBeFalse()
        ->and(fn () => $service->submit($offer, $f['recruiter']))->toThrow(RuntimeException::class, 'salary');

    $offer = $service->update($offer, offerData($f), $f['hr']);
    $offer = $service->submit($offer, $f['hr']);

    expect($service->canSeeSalary($f['hr'], $offer))->toBeTrue()
        ->and($service->canSeeSalary($f['manager'], $offer))->toBeTrue()
        ->and($service->canView($f['manager'], $offer))->toBeTrue()
        ->and($service->canSeeSalary($f['recruiter'], $offer))->toBeFalse()
        ->and($service->canSeeSalary($employee, $offer))->toBeFalse()
        // The CEO is not an approver of this offer (salary below the threshold).
        ->and($service->canSeeSalary($f['ceo'], $offer))->toBeFalse()
        // A recruiter's later edit cannot touch the salary it cannot see.
        ->and((string) $offer->salary_gross)->toBe('250000.00');

    // The Offers list: the recruiter sees the offer without the amount; HR sees it.
    test()->actingAs($f['recruiter']);
    CurrentCompany::flush();
    Livewire::test(ListJobOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertSee('Restricted')
        ->assertDontSee('PKR 250,000');

    test()->actingAs($f['hr']);
    Livewire::test(ListJobOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertSee('PKR 250,000');

    // The hiring manager has no recruitment rights, yet sees the offer they approve, with the salary.
    test()->actingAs($f['manager']);
    CurrentCompany::flush();
    expect(app(JobOfferPolicy::class)->viewAny($f['manager']))->toBeTrue()
        ->and(JobOfferResource::getEloquentQuery()->pluck('id')->all())->toBe([$offer->id]);
    $this->get(JobOfferResource::getUrl('view', ['record' => $offer]))->assertOk()->assertSee('PKR 250,000');

    // Anyone else in the company does not get it.
    expect($this->actingAs(jobOpeningUser($f['company']))->get(JobOfferResource::getUrl('view', ['record' => $offer]))->status())->toBeIn([403, 404]);
});

it('makes an offer from the application\'s Offers tab', function () {
    $f = offerFixture();
    test()->actingAs($f['hr']);
    CurrentCompany::flush();

    Livewire::test(OffersRelationManager::class, ['ownerRecord' => $f['applicant']->refresh(), 'pageClass' => ViewApplicant::class])
        ->callTableAction('makeOfferFromTab', data: offerData($f))
        ->assertHasNoTableActionErrors();

    $offer = JobOffer::query()->where('applicant_id', $f['applicant']->id)->firstOrFail();

    expect($offer->status)->toBe(JobOfferStatus::DRAFT)
        ->and($offer->reference)->toBe(sprintf('OFR-%d-%05d', $f['company']->id, $offer->id))
        ->and((string) $offer->salary_gross)->toBe('250000.00')
        ->and($offer->salary_breakdown)->toEqual(['basic' => '150000.00', 'house_rent' => '67500.00', 'utilities' => '15000.00', 'allowances' => '17500.00']);

    Livewire::test(OffersRelationManager::class, ['ownerRecord' => $f['applicant']->refresh(), 'pageClass' => ViewApplicant::class])
        ->assertCanSeeTableRecords([$offer])
        ->assertTableActionHidden('makeOfferFromTab')
        ->callTableAction('submitOffer', $offer, data: ['note' => 'Ready'])
        ->assertHasNoTableActionErrors();

    expect($offer->refresh()->status)->toBe(JobOfferStatus::PENDING_APPROVAL);
});

it('provisions the offer workflow and default template for a new company', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $workflow = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'job_offer')->with('steps')->firstOrFail();

    expect($workflow->steps->pluck('hierarchy_route', 'sequence')->only([1, 2])->all())->toBe([1 => 'record_manager', 2 => 'hr'])
        ->and($workflow->steps->firstWhere('sequence', 3)->conditions)->toEqual([['field' => 'ceo_approval', 'operator' => 'equals', 'value' => '1']])
        ->and(OfferLetterTemplate::preferredFor($company->id)?->body)->toContain('{{candidate_name}}');

    // Idempotent.
    app(JobOfferDefaultsSeeder::class)->provisionForCompany($company);
    expect(ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'job_offer')->count())->toBe(1)
        ->and($workflow->steps()->count())->toBe(3)
        ->and(OfferLetterTemplate::query()->where('company_id', $company->id)->count())->toBe(1);
});

it('lets HR of the company edit the offer letter templates and the CEO threshold, and nobody else', function () {
    $f = offerFixture();
    test()->actingAs($f['hr']);
    CurrentCompany::flush();

    Livewire::test(ManageOfferLetterTemplates::class)
        ->assertCanSeeTableRecords(OfferLetterTemplate::query()->where('company_id', $f['company']->id)->get())
        ->callAction('create', data: [
            'name'          => 'Senior staff offer',
            'email_subject' => 'Your offer from {{company_name}}',
            'body'          => '<p>Dear {{candidate_name}}, welcome aboard as {{position}}.</p>',
            'is_default'    => true,
            'is_active'     => true,
        ])
        ->assertHasNoActionErrors()
        ->callAction('offerSettings', data: ['ceo_approval_threshold' => 500000])
        ->assertHasNoActionErrors();

    $template = OfferLetterTemplate::query()->where('company_id', $f['company']->id)->where('name', 'Senior staff offer')->firstOrFail();

    expect($template->is_default)->toBeTrue()
        ->and($template->body)->toContain('candidate_name')
        ->and(OfferLetterTemplate::preferredFor($f['company']->id)?->id)->toBe($template->id)
        ->and(OfferSetting::ceoThresholdFor($f['company']->id))->toBe('500000.00');

    // The next offer uses it.
    $offer = approvedOffer($f);
    expect(app(OfferLetterRenderer::class)->renderBody($offer))->toContain('Dear Ayesha Khan, welcome aboard as Senior Customs Clearance Officer.')
        ->and(app(OfferLetterRenderer::class)->emailSubject($offer))->toBe('Your offer from '.$f['company']->name);

    // A recruiter, and HR of another company, do not get the templates.
    $this->actingAs($f['recruiter'])->get(OfferLetterTemplateResource::getUrl('index'))->assertForbidden();
    $other = offerFixture();
    test()->actingAs($other['hr']);
    CurrentCompany::flush();
    expect(OfferLetterTemplateResource::getEloquentQuery()->where('company_id', $f['company']->id)->exists())->toBeFalse()
        ->and(app(OfferLetterTemplatePolicy::class)->update($other['hr'], $template))->toBeFalse();
});

it('runs approval, sending and the hire from the offer page', function () {
    $f = offerFixture();
    $service = app(JobOfferService::class);
    $offer = $service->submit(draftOffer($f), $f['hr']);
    $page = function (User $user) use ($offer) {
        test()->actingAs($user);
        CurrentCompany::flush();

        return Livewire::test(ViewJobOffer::class, ['record' => $offer->getKey()]);
    };

    // The hiring manager approves from the page; the recruiter sees no decision buttons.
    $page($f['recruiter'])->assertActionHidden('approveOffer')->assertActionHidden('sendOffer');
    $page($f['manager'])->assertActionVisible('approveOffer')->callAction('approveOffer', data: ['comment' => 'Yes'])->assertHasNoActionErrors();
    $page($f['hr2'])->callAction('approveOffer')->assertHasNoActionErrors();

    expect($offer->refresh()->status)->toBe(JobOfferStatus::APPROVED);

    $page($f['hr'])
        ->assertActionVisible('downloadLetter')
        ->callAction('sendOffer', data: ['email' => 'ayesha.khan@example.com'])
        ->assertHasNoActionErrors();

    expect($offer->refresh()->status)->toBe(JobOfferStatus::SENT)
        ->and(offerMailJob())->not->toBeNull();

    $service->respond($offer, $offer->response_token, true);

    $page($f['hr'])
        ->assertActionVisible('createEmployeeFromOffer')
        ->callAction('createEmployeeFromOffer', data: ['joining_date' => $offer->joining_date->toDateString(), 'department_id' => $f['department']->id, 'parent_id' => $f['managerEmployee']->id])
        ->assertHasNoActionErrors();

    expect($offer->refresh()->employee_id)->not->toBeNull();
});
