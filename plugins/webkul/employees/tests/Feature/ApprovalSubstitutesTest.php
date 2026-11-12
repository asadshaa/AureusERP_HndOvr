<?php

/**
 * Employees -> Substitute approvers: naming a substitute for a date range, who
 * may name one for whom, what each person sees, and how the approval engine
 * honours the arrangement (only inside the range, never after it is ended,
 * never on the substitute's own requests).
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\ApprovalSubstitutes;
use Webkul\Employee\Models\ApprovalDelegation;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Reporting\DelegationService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function asbUser(Company $company, array $permissions = [], string $name = 'Person'): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($permissions !== []) {
        $role = Role::query()->firstOrCreate(['name' => 'asb_test_'.md5(implode(',', $permissions)), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    return $user->fresh();
}

function asbActAs(User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

beforeEach(function (): void {
    // Wednesday 28 Oct 2026, 12:00 in Karachi.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 07:00:00', 'UTC'));
    ApprovalEngine::flushMemo();

    $this->company = Company::factory()->create(['is_active' => true]);
    $this->hr = asbUser($this->company, [HrPermissions::ManageReportingLines], 'Hira HR');
    $this->approver = asbUser($this->company, ['view_any_support_approval::request'], 'Amir Approver');
    $this->substitute = asbUser($this->company, [], 'Sana Substitute');
    $this->service = app(DelegationService::class);
});

function asbRequest(Company $company, User $approver, User $requester): ApprovalRequest
{
    $workflow = ApprovalWorkflow::query()->create([
        'company_id' => $company->id, 'name' => 'Substitute test', 'request_type' => 'substitute_test_'.$approver->id.'_'.$requester->id, 'priority' => 100, 'is_active' => true,
    ]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Approver', 'approver_user_id' => $approver->id, 'required_approvals' => 1]);

    return app(ApprovalEngine::class)->submit($company, $requester, $workflow->request_type, null, ['company_id' => $company->id])->fresh(['workflow.steps']);
}

it('opens for HR and for people who use the approvals queue, not for others', function (): void {
    asbActAs($this->hr);
    expect(ApprovalSubstitutes::canAccess())->toBeTrue();

    asbActAs($this->approver);
    expect(ApprovalSubstitutes::canAccess())->toBeTrue();

    asbActAs($this->substitute);
    expect(ApprovalSubstitutes::canAccess())->toBeFalse();
});

it('lets a person name a substitute for themselves through the page', function (): void {
    asbActAs($this->approver);

    Livewire::test(ApprovalSubstitutes::class)
        ->assertOk()
        ->assertActionVisible('help')
        ->mountAction('name')
        ->set('mountedActions.0.data', [
            'from_user_id' => $this->approver->id,
            'to_user_id'   => $this->substitute->id,
            'starts_on'    => '2026-10-28',
            'ends_on'      => '2026-11-03',
            'reason'       => 'Annual leave',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $delegation = ApprovalDelegation::query()->where('company_id', $this->company->id)->firstOrFail();
    expect($delegation->from_user_id)->toBe($this->approver->id)
        ->and($delegation->to_user_id)->toBe($this->substitute->id)
        ->and($delegation->created_by)->toBe($this->approver->id)
        ->and($delegation->reason)->toBe('Annual leave');

    Livewire::test(ApprovalSubstitutes::class)
        ->assertCanSeeTableRecords([$delegation])
        ->assertSee('In force')
        ->assertSee('Sana Substitute');
});

it('does not let an employee name a substitute for somebody else', function (): void {
    $colleague = asbUser($this->company, ['view_any_support_approval::request'], 'Colleague');
    asbActAs($this->approver);

    Livewire::test(ApprovalSubstitutes::class)
        ->mountAction('name')
        ->set('mountedActions.0.data', [
            'from_user_id' => $colleague->id,
            'to_user_id'   => $this->substitute->id,
            'starts_on'    => '2026-10-28',
            'ends_on'      => '2026-11-03',
        ])
        ->callMountedAction();

    expect(ApprovalDelegation::query()->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(fn () => $this->service->create($this->approver, $this->company->id, $colleague, $this->substitute, '2026-10-28', '2026-11-03'))
        ->toThrow(RuntimeException::class, 'your own approvals');
});

it('lets HR name a substitute for anybody in the company', function (): void {
    asbActAs($this->hr);

    Livewire::test(ApprovalSubstitutes::class)
        ->mountAction('name')
        ->set('mountedActions.0.data', [
            'from_user_id' => $this->approver->id,
            'to_user_id'   => $this->substitute->id,
            'starts_on'    => '2026-10-30',
            'ends_on'      => '2026-11-10',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $delegation = ApprovalDelegation::query()->where('company_id', $this->company->id)->firstOrFail();
    expect($delegation->from_user_id)->toBe($this->approver->id)
        ->and($delegation->created_by)->toBe($this->hr->id);

    Livewire::test(ApprovalSubstitutes::class)->assertSee('Coming up');
});

it('refuses a substitute from another company and a stretch longer than 90 days', function (): void {
    $outsider = asbUser(Company::factory()->create(['is_active' => true]));

    expect(fn () => $this->service->create($this->hr, $this->company->id, $this->approver, $outsider, '2026-10-28', '2026-11-03'))
        ->toThrow(InvalidArgumentException::class, 'cannot act in this company');
    expect(fn () => $this->service->create($this->hr, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2027-03-01'))
        ->toThrow(InvalidArgumentException::class, 'at most 90 days');
});

it('shows an employee only the arrangements they made or that name them', function (): void {
    $other = asbUser($this->company, ['view_any_support_approval::request'], 'Other Approver');
    $mine = $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');
    $namesMe = $this->service->create($this->hr, $this->company->id, $other, $this->approver, '2026-10-28', '2026-11-03');
    $unrelated = $this->service->create($this->hr, $this->company->id, $other, $this->substitute, '2026-10-28', '2026-11-03');

    asbActAs($this->approver);
    Livewire::test(ApprovalSubstitutes::class)
        ->assertCanSeeTableRecords([$mine, $namesMe])
        ->assertCanNotSeeTableRecords([$unrelated]);

    asbActAs($this->hr);
    Livewire::test(ApprovalSubstitutes::class)->assertCanSeeTableRecords([$mine, $namesMe, $unrelated]);
});

it('keeps the list to the current company', function (): void {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherHr = asbUser($otherCompany, [HrPermissions::ManageReportingLines], 'Other HR');
    $a = asbUser($otherCompany, [], 'Alpha');
    $b = asbUser($otherCompany, [], 'Beta');
    $foreign = $this->service->create($otherHr, $otherCompany->id, $a, $b, '2026-10-28', '2026-11-03');
    $own = $this->service->create($this->hr, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');

    asbActAs($this->hr);
    Livewire::test(ApprovalSubstitutes::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('ends an arrangement from the page, for the people allowed to', function (): void {
    $delegation = $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');
    $stranger = asbUser($this->company, ['view_any_support_approval::request'], 'Stranger');

    asbActAs($stranger);
    expect($this->service->canRevoke($stranger, $delegation))->toBeFalse();
    Livewire::test(ApprovalSubstitutes::class)->assertCanNotSeeTableRecords([$delegation]);

    asbActAs($this->approver);
    Livewire::test(ApprovalSubstitutes::class)
        ->assertTableActionVisible('end', $delegation)
        ->callTableAction('end', $delegation);

    expect($delegation->fresh()->revoked_at)->not->toBeNull()
        ->and($delegation->fresh()->revoked_by)->toBe($this->approver->id);

    Livewire::test(ApprovalSubstitutes::class)->assertSee('Ended early');
});

it('lets HR end anybody\'s arrangement', function (): void {
    $delegation = $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');

    asbActAs($this->hr);
    Livewire::test(ApprovalSubstitutes::class)->callTableAction('end', $delegation);

    expect($delegation->fresh()->revoked_at)->not->toBeNull();
});

it('lets the engine use the substitute only inside the date range', function (): void {
    $requester = asbUser($this->company, [], 'Rida Requester');
    $request = asbRequest($this->company, $this->approver, $requester);
    $engine = app(ApprovalEngine::class);

    expect($engine->canAct($request->fresh(['workflow.steps']), $this->substitute))->toBeFalse();

    $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-30', '2026-11-02');
    ApprovalEngine::flushMemo();
    expect($engine->canAct($request->fresh(['workflow.steps']), $this->substitute))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00', 'UTC'));
    ApprovalEngine::flushMemo();
    $fresh = $request->fresh(['workflow.steps']);
    expect($engine->canAct($fresh, $this->substitute))->toBeTrue()
        ->and($engine->resolveAuthorizedApprovers($fresh)->pluck('id')->all())->toContain($this->substitute->id, $this->approver->id);

    $this->travelTo(CarbonImmutable::parse('2026-11-03 07:00:00', 'UTC'));
    ApprovalEngine::flushMemo();
    expect($engine->canAct($request->fresh(['workflow.steps']), $this->substitute))->toBeFalse();
});

it('stops the substitute as soon as the arrangement is ended', function (): void {
    $requester = asbUser($this->company, [], 'Rida Requester');
    $request = asbRequest($this->company, $this->approver, $requester);
    $engine = app(ApprovalEngine::class);

    $delegation = $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');
    expect($engine->canAct($request->fresh(['workflow.steps']), $this->substitute))->toBeTrue();

    $this->service->revoke($this->approver, $delegation);

    expect($engine->canAct($request->fresh(['workflow.steps']), $this->substitute))->toBeFalse();
});

it('never lets the substitute approve their own request', function (): void {
    Employee::query()->create([
        'company_id'      => $this->company->id, 'user_id' => $this->substitute->id, 'name' => 'Sana Substitute',
        'employee_number' => 'ASB-'.$this->substitute->id, 'employment_status' => 'active', 'is_active' => true,
    ]);
    $request = asbRequest($this->company, $this->approver, $this->substitute->fresh());
    $engine = app(ApprovalEngine::class);

    $this->service->create($this->approver, $this->company->id, $this->approver, $this->substitute, '2026-10-28', '2026-11-03');
    ApprovalEngine::flushMemo();
    $fresh = $request->fresh(['workflow.steps']);

    expect($engine->canAct($fresh, $this->substitute))->toBeFalse()
        ->and($engine->resolveAuthorizedApprovers($fresh)->pluck('id')->all())->not->toContain($this->substitute->id);
});
