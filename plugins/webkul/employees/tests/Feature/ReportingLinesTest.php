<?php

/**
 * Reporting lines: a line manager per person (employees.parent_id) and, for one
 * purpose, a different manager. Covers loop protection, validated changes with
 * history and notifications, the HR self-edit approval, bulk reassign, and what
 * the approval engine does with it: the purpose manager, then the line manager,
 * then the line manager's manager, then HR; and today's substitute approver.
 * Fresh factory companies only.
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\DelegationService;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 06:00:00', 'UTC'));
    $this->lines = app(ReportingLineService::class);
    $this->engine = app(ApprovalEngine::class);
    ApprovalEngine::flushMemo();
    ReportingLineService::flushMemo();
});

function rlsUser(Company $company, string $name, ?string $role = null, bool $active = true): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => $active]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null) {
        $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        if ($role === 'hr_manager') {
            $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => HrPermissions::ManageReportingLines, 'guard_name' => 'web']));
        }
        $user->assignRole($roleModel);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function rlsPerson(Company $company, string $name, ?Employee $manager = null, bool $withLogin = true, ?string $role = null): array
{
    $user = $withLogin ? rlsUser($company, $name, $role) : null;
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $user?->id, 'parent_id' => $manager?->id, 'name' => $name, 'is_active' => true]);

    return [$employee, $user];
}

/**
 * Ceo <- Director <- Lead <- Esa (the employee), a spare manager, and HR; one
 * line-manager step workflow for leave and one for a claim.
 *
 * @return array<string, mixed>
 */
function rlsFixture(): array
{
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);

    [$ceo, $ceoUser] = rlsPerson($company, 'Chief Exec');
    [$director, $directorUser] = rlsPerson($company, 'Dina Director', $ceo);
    [$lead, $leadUser] = rlsPerson($company, 'Lara Lead', $director);
    [$esa, $esaUser] = rlsPerson($company, 'Esa Employee', $lead);
    [$spare, $spareUser] = rlsPerson($company, 'Sami Spare', $ceo);
    [$hrPerson, $hrUser] = rlsPerson($company, 'Hana HR', null, true, 'hr_manager');
    [$hrTwo, $hrTwoUser] = rlsPerson($company, 'Hira HR', null, true, 'hr_manager');

    foreach (['leave_request', 'claim_travel_entertainment'] as $type) {
        $workflow = ApprovalWorkflow::query()->create(['company_id' => $company->id, 'request_type' => $type, 'name' => $type, 'is_active' => true]);
        $workflow->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    }

    return compact('company', 'ceo', 'ceoUser', 'director', 'directorUser', 'lead', 'leadUser', 'esa', 'esaUser', 'spare', 'spareUser', 'hrPerson', 'hrUser', 'hrTwo', 'hrTwoUser');
}

function rlsSubmit(array $f, string $type = 'leave_request', ?Employee $subject = null, ?User $requester = null): ApprovalRequest
{
    return app(ApprovalEngine::class)->submit($subject ?? $f['esa'], $requester ?? $f['esaUser'], $type, null, ['company_id' => (int) $f['company']->id]);
}

// ---------------------------------------------------------------------
// Loop protection
// ---------------------------------------------------------------------
it('refuses to save a line manager that would make a loop, on every write path', function () {
    $f = rlsFixture();

    // Under their own subordinate (two levels down) and under themselves.
    expect(fn () => $f['director']->update(['parent_id' => $f['esa']->id]))->toThrow(InvalidArgumentException::class, 'already reports to Dina Director')
        ->and(fn () => $f['esa']->update(['parent_id' => $f['esa']->id]))->toThrow(InvalidArgumentException::class, 'cannot be their own manager')
        ->and($f['director']->fresh()->parent_id)->toBe($f['ceo']->id);

    // An ordinary move and a save that does not touch the manager are fine.
    $f['esa']->update(['parent_id' => $f['spare']->id]);
    $f['esa']->update(['name' => 'Esa E.']);

    expect($f['esa']->fresh()->parent_id)->toBe($f['spare']->id);
});

// ---------------------------------------------------------------------
// Changing the line manager
// ---------------------------------------------------------------------
it('changes a line manager, records the history and tells the people concerned', function () {
    $f = rlsFixture();

    $change = $this->lines->change($f['hrUser'], $f['esa'], null, $f['spare']->id, 'Moved to the new team');

    expect($change->status)->toBe(ReportingChange::Applied)
        ->and($change->old_manager_employee_id)->toBe($f['lead']->id)
        ->and($change->new_manager_employee_id)->toBe($f['spare']->id)
        ->and($change->requested_by)->toBe($f['hrUser']->id)
        ->and($change->reason)->toBe('Moved to the new team')
        ->and($f['esa']->fresh()->parent_id)->toBe($f['spare']->id);

    $told = fn (User $user) => DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', '%reporting_change_'.$change->id.'_%')->count();
    expect($told($f['esaUser']))->toBe(1)->and($told($f['spareUser']))->toBe(1)->and($told($f['leadUser']))->toBe(1)->and($told($f['hrUser']))->toBe(0);
});

it('refuses wrong changes with plain messages and nobody else may change reporting lines', function () {
    $f = rlsFixture();
    $other = Company::factory()->create(['currency_id' => $f['company']->currency_id, 'is_active' => true]);
    [$outsider] = rlsPerson($other, 'Olga Outsider');
    [, $otherHrUser] = rlsPerson($other, 'Omar HR', null, true, 'hr_manager');
    [$inactive] = [Employee::query()->create(['company_id' => $f['company']->id, 'name' => 'Ian Inactive', 'is_active' => false])];

    $try = fn (?User $actor, Employee $employee, ?int $manager, ?string $purpose = null) => fn () => app(ReportingLineService::class)->change($actor ?? $f['hrUser'], $employee, $purpose, $manager);

    expect($try(null, $f['esa'], $f['esa']->id))->toThrow(InvalidArgumentException::class, 'cannot be their own manager')
        ->and($try(null, $f['director'], $f['esa']->id))->toThrow(InvalidArgumentException::class, 'already reports to Dina Director')
        ->and($try(null, $f['esa'], $outsider->id))->toThrow(InvalidArgumentException::class, 'works in another company')
        ->and($try(null, $f['esa'], $inactive->id))->toThrow(InvalidArgumentException::class, 'is not an active employee')
        ->and($try(null, $f['esa'], $f['lead']->id))->toThrow(RuntimeException::class, 'Nothing to change')
        ->and($try(null, $f['esa'], $f['spare']->id, 'nonsense'))->toThrow(InvalidArgumentException::class, 'what the manager approves')
        ->and($try($f['esaUser'], $f['spare'], $f['lead']->id))->toThrow(RuntimeException::class, 'may not change reporting lines')
        ->and($try($otherHrUser, $f['esa'], $f['spare']->id))->toThrow(RuntimeException::class, 'may not change reporting lines')
        ->and(ReportingChange::query()->where('company_id', $f['company']->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------
// A manager for one purpose
// ---------------------------------------------------------------------
it('gives a person a different approver for one purpose and the approval engine follows it', function () {
    $f = rlsFixture();

    $change = $this->lines->change($f['hrUser'], $f['esa'], ReportingPurposes::Claims, $f['spare']->id, 'Claims go to Sami');
    expect($change->status)->toBe(ReportingChange::Applied)
        ->and($this->lines->managerFor($f['esa']->fresh(), ReportingPurposes::Claims)->id)->toBe($f['spare']->id)
        ->and($this->lines->managerFor($f['esa']->fresh(), ReportingPurposes::Leave)->id)->toBe($f['lead']->id)
        ->and($f['esa']->fresh()->parent_id)->toBe($f['lead']->id);

    $claim = rlsSubmit($f, 'claim_travel_entertainment');
    $leave = rlsSubmit($f, 'leave_request');

    expect($this->engine->canAct($claim, $f['spareUser']))->toBeTrue()
        ->and($this->engine->canAct($claim, $f['leadUser']))->toBeFalse()
        ->and($this->engine->resolveAuthorizedApprovers($claim)->pluck('id')->all())->toBe([$f['spareUser']->id])
        ->and($this->engine->canAct($leave, $f['leadUser']))->toBeTrue()
        ->and($this->engine->canAct($leave, $f['spareUser']))->toBeFalse();

    // Back to the line manager.
    $this->lines->change($f['hrUser'], $f['esa']->fresh(), ReportingPurposes::Claims, null);
    ApprovalEngine::flushMemo();

    expect($this->lines->hasOwnManager($f['esa']->fresh(), ReportingPurposes::Claims))->toBeFalse()
        ->and($this->engine->canAct($claim->fresh(), $f['leadUser']))->toBeTrue()
        ->and($this->engine->canAct($claim->fresh(), $f['spareUser']))->toBeFalse();
});

it('does not accept a purpose manager without a login or one who reports to the person', function () {
    $f = rlsFixture();
    [$noLogin] = rlsPerson($f['company'], 'Nora NoLogin', null, false);

    expect(fn () => $this->lines->change($f['hrUser'], $f['esa'], ReportingPurposes::Leave, $noLogin->id))->toThrow(InvalidArgumentException::class, 'has no active login')
        ->and(fn () => $this->lines->change($f['hrUser'], $f['director'], ReportingPurposes::Claims, $f['esa']->id))->toThrow(InvalidArgumentException::class, 'already reports to Dina Director');
});

it('keeps the leave manager column and open appraisals in step with the purpose managers', function () {
    $f = rlsFixture();
    expect($f['esa']->fresh()->leave_manager_id)->toBe($f['leadUser']->id);

    $this->lines->change($f['hrUser'], $f['esa'], ReportingPurposes::Leave, $f['spare']->id);
    expect($f['esa']->fresh()->leave_manager_id)->toBe($f['spareUser']->id);

    $this->lines->change($f['hrUser'], $f['esa']->fresh(), ReportingPurposes::Leave, null);
    expect($f['esa']->fresh()->leave_manager_id)->toBe($f['leadUser']->id);

    $cycle = PerformanceCycle::query()->forceCreate(['company_id' => $f['company']->id, 'name' => 'H2 2026', 'status' => 'draft', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31']);
    $review = PerformanceReview::query()->forceCreate(['company_id' => $f['company']->id, 'cycle_id' => $cycle->id, 'employee_id' => $f['esa']->id, 'reviewer_id' => $f['lead']->id, 'status' => AppraisalKinds::StatusManagerReview]);

    $this->lines->change($f['hrUser'], $f['esa']->fresh(), ReportingPurposes::Appraisal, $f['spare']->id);
    expect($review->fresh()->reviewer_id)->toBe($f['spare']->id);

    $this->lines->change($f['hrUser'], $f['esa']->fresh(), null, $f['director']->id);
    expect($review->fresh()->reviewer_id)->toBe($f['spare']->id); // the appraisal manager set for appraisals stays
});

// ---------------------------------------------------------------------
// Who approves when the manager cannot
// ---------------------------------------------------------------------
it('goes to the line manager, then the line manager\'s manager, then HR', function () {
    $f = rlsFixture();
    $request = rlsSubmit($f);

    expect($this->engine->resolveAuthorizedApprovers($request)->pluck('id')->all())->toBe([$f['leadUser']->id]);

    User::query()->whereKey($f['leadUser']->id)->update(['is_active' => false]);
    ApprovalEngine::flushMemo();
    expect($this->engine->resolveAuthorizedApprovers($request->fresh())->pluck('id')->all())->toBe([$f['directorUser']->id])
        ->and($this->engine->canAct($request->fresh(), $f['directorUser']))->toBeTrue()
        ->and($this->engine->canAct($request->fresh(), $f['hrUser']))->toBeFalse();

    User::query()->whereKey($f['directorUser']->id)->update(['is_active' => false]);
    ApprovalEngine::flushMemo();
    expect($this->engine->resolveAuthorizedApprovers($request->fresh())->pluck('id')->sort()->values()->all())->toBe(collect([$f['hrUser']->id, $f['hrTwoUser']->id])->sort()->values()->all())
        ->and($this->engine->canAct($request->fresh(), $f['hrUser']))->toBeTrue();

    // A person with no manager at all goes straight to HR.
    $orphanRequest = rlsSubmit($f, 'leave_request', $f['ceo'], $f['ceoUser']);
    expect($this->engine->canAct($orphanRequest, $f['hrUser']))->toBeTrue()->and($this->engine->canAct($orphanRequest, $f['esaUser']))->toBeFalse();
});

it('moves a waiting approval to the new manager and tells them it now waits for them', function () {
    $f = rlsFixture();
    $request = rlsSubmit($f);
    expect($this->engine->canAct($request, $f['leadUser']))->toBeTrue();

    $change = $this->lines->change($f['hrUser'], $f['esa'], null, $f['spare']->id);
    ApprovalEngine::flushMemo();

    expect($this->engine->canAct($request->fresh(), $f['spareUser']))->toBeTrue()
        ->and($this->engine->canAct($request->fresh(), $f['leadUser']))->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $f['spareUser']->id)->where('data', 'like', '%reporting_change_'.$change->id.'_reroute-'.$request->id.'_%')->count())->toBe(1);
});

// ---------------------------------------------------------------------
// HR editing her own line
// ---------------------------------------------------------------------
it('keeps a change HR makes to her own line pending until someone else approves it', function () {
    $f = rlsFixture();

    $change = $this->lines->change($f['hrUser'], $f['hrPerson'], null, $f['ceo']->id, 'I report to the CEO');

    expect($change->status)->toBe(ReportingChange::Pending)
        ->and($f['hrPerson']->fresh()->parent_id)->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $f['hrTwoUser']->id)->where('data', 'like', '%reporting_change_'.$change->id.'_pending-%')->count())->toBe(1);

    expect(fn () => $this->lines->decide($f['hrUser'], $change, true))->toThrow(RuntimeException::class, 'must be decided by someone else')
        ->and(fn () => $this->lines->decide($f['esaUser'], $change, true))->toThrow(RuntimeException::class, 'may not decide');

    $approved = $this->lines->decide($f['hrTwoUser'], $change, true, 'Agreed');

    expect($approved->status)->toBe(ReportingChange::Applied)
        ->and($approved->decided_by)->toBe($f['hrTwoUser']->id)
        ->and($f['hrPerson']->fresh()->parent_id)->toBe($f['ceo']->id)
        ->and(fn () => $this->lines->decide($f['hrTwoUser'], $approved, true))->toThrow(RuntimeException::class, 'already been decided');
});

it('rejects a pending change without applying it', function () {
    $f = rlsFixture();
    $change = $this->lines->change($f['hrUser'], $f['hrPerson'], ReportingPurposes::Leave, $f['ceo']->id);

    $rejected = $this->lines->decide($f['hrTwoUser'], $change, false, 'Not now');

    expect($rejected->status)->toBe(ReportingChange::Rejected)
        ->and($rejected->decision_note)->toBe('Not now')
        ->and($this->lines->hasOwnManager($f['hrPerson']->fresh(), ReportingPurposes::Leave))->toBeFalse();
});

// ---------------------------------------------------------------------
// Bulk
// ---------------------------------------------------------------------
it('reassigns several people and reports the ones that could not be moved', function () {
    $f = rlsFixture();
    [$tom] = rlsPerson($f['company'], 'Tom Team', $f['lead']);

    // Moving Lara Lead, Esa and Tom under Esa: Esa cannot report to themselves and Lara would loop.
    $result = $this->lines->reassign($f['hrUser'], [$f['lead']->id, $f['esa']->id, $tom->id], null, $f['esa']->id, 'Restructure');

    expect($result['changed'])->toBe(1)
        ->and(collect($result['failed'])->pluck('employee')->sort()->values()->all())->toBe(['Esa Employee', 'Lara Lead'])
        ->and($tom->fresh()->parent_id)->toBe($f['esa']->id)
        ->and($f['lead']->fresh()->parent_id)->toBe($f['director']->id);
});

// ---------------------------------------------------------------------
// What a person's flow shows
// ---------------------------------------------------------------------
it('shows each approval flow with the real names for the person', function () {
    $f = rlsFixture();
    $this->lines->change($f['hrUser'], $f['esa'], ReportingPurposes::Claims, $f['spare']->id);

    $groups = collect($this->lines->flowFor($f['esa']->fresh()))->keyBy('purpose_label');

    // A new company also gets the standard attendance, loan and overtime flows.
    expect($groups->keys()->all())->toContain('Leave', 'Claims', 'Attendance')
        ->and($groups->keys()->first())->toBe('Leave')
        ->and($groups['Leave']['flows'][0]['steps'][0]['who'])->toBe('Lara Lead (line manager)')
        ->and($groups['Claims']['flows'][0]['steps'][0]['who'])->toBe('Sami Spare (Claims manager)');

    User::query()->whereKey($f['leadUser']->id)->update(['is_active' => false]);
    $groups = collect($this->lines->flowFor($f['esa']->fresh()))->keyBy('purpose_label');
    expect($groups['Leave']['flows'][0]['steps'][0]['who'])->toBe("Dina Director (line manager's manager)");
});

// ---------------------------------------------------------------------
// Substitute approver (engine side)
// ---------------------------------------------------------------------
it('lets today\'s substitute decide what waits for the person, but never their own request, and not after it ends', function () {
    $f = rlsFixture();
    $delegations = app(DelegationService::class);
    $request = rlsSubmit($f);

    // Nobody stands in yet.
    expect($this->engine->canAct($request, $f['spareUser']))->toBeFalse();

    $delegation = $delegations->create($f['hrUser'], (int) $f['company']->id, $f['leadUser'], $f['spareUser'], '2026-11-16', '2026-11-20', 'Lara is away');
    ApprovalEngine::flushMemo();

    expect($this->engine->canAct($request->fresh(), $f['spareUser']))->toBeTrue()
        ->and($this->engine->canAct($request->fresh(), $f['leadUser']))->toBeTrue()
        ->and($this->engine->resolveAuthorizedApprovers($request->fresh())->pluck('id')->sort()->values()->all())->toBe(collect([$f['leadUser']->id, $f['spareUser']->id])->sort()->values()->all());

    // A substitute never decides a request about themselves, even one waiting for the person they stand in for:
    // Lara stands in for her own manager Dina, and her own leave request waits for Dina.
    $delegations->create($f['hrUser'], (int) $f['company']->id, $f['directorUser'], $f['leadUser'], '2026-11-16', '2026-11-20');
    ApprovalEngine::flushMemo();
    $own = rlsSubmit($f, 'leave_request', $f['lead'], $f['leadUser']);
    expect($this->engine->canAct($own, $f['directorUser']))->toBeTrue()
        ->and($this->engine->canAct($own, $f['leadUser']))->toBeFalse();

    // Revoked: it stops at once.
    $delegations->revoke($f['hrUser'], $delegation);
    ApprovalEngine::flushMemo();
    expect($this->engine->canAct($request->fresh(), $f['spareUser']))->toBeFalse();

    // After the last day it is over without anyone doing anything.
    $delegations->create($f['hrUser'], (int) $f['company']->id, $f['leadUser'], $f['spareUser'], '2026-11-16', '2026-11-17');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 06:00:00', 'UTC'));
    ApprovalEngine::flushMemo();
    expect($this->engine->canAct($request->fresh(), $f['spareUser']))->toBeFalse();
});

it('decides a change taken straight from the history list', function () {
    $f = rlsFixture();
    $this->lines->change($f['hrUser'], $f['hrPerson'], null, $f['ceo']->id, 'I report to the CEO');

    // The history list loads people with only their name; deciding must still see the whole record.
    $fromHistory = $this->lines->historyQuery((int) $f['company']->id)->where('status', ReportingChange::Pending)->firstOrFail();
    $approved = $this->lines->decide($f['hrTwoUser'], $fromHistory, true);

    expect($approved->status)->toBe(ReportingChange::Applied)
        ->and($f['hrPerson']->fresh()->parent_id)->toBe($f['ceo']->id);
});
