<?php

/**
 * Employees -> Approval flows: HR edits the approval steps of her own
 * company's flows. The rules live in ApprovalFlowService; the page only calls it.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\ApprovalFlows;
use Webkul\Employee\Services\Reporting\ApprovalFlowService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalDecision;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function aflCompany(): Company
{
    $company = Company::factory()->create(['is_active' => true]);
    // New companies are given the standard flows; these tests build their own.
    ApprovalWorkflow::query()->where('company_id', $company->id)->delete();

    return $company;
}

function aflUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($permissions !== []) {
        $role = Role::query()->firstOrCreate(['name' => 'afl_test_'.md5(implode(',', $permissions)), 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    return $user->fresh();
}

function aflHr(Company $company): User
{
    return aflUser($company, [HrPermissions::ManageApprovalFlows]);
}

function aflActAs(User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

/** A leave flow with two steps: the line manager, then HR. */
function aflWorkflow(Company $company, string $type = 'leave_request'): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::query()->create([
        'company_id' => $company->id, 'name' => 'Leave approval', 'request_type' => $type, 'priority' => 100, 'is_active' => true,
    ]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Manager', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    $workflow->steps()->create(['sequence' => 2, 'name' => 'HR', 'hierarchy_route' => 'hr', 'required_approvals' => 1]);

    return $workflow->load('steps');
}

/** @return array<int, array<string, mixed>> the workflow's steps as the form would send them back */
function aflSteps(ApprovalWorkflow $workflow): array
{
    return $workflow->steps->sortBy('sequence')->map(fn (ApprovalStep $step): array => [
        'id'                 => $step->id,
        'name'               => $step->name,
        'hierarchy_route'    => $step->hierarchy_route,
        'approver_user_id'   => $step->approver_user_id,
        'approver_role_id'   => $step->approver_role_id,
        'required_approvals' => $step->required_approvals,
        'sla_hours'          => $step->sla_hours,
    ])->values()->all();
}

function aflPending(ApprovalWorkflow $workflow, User $requester): ApprovalRequest
{
    return ApprovalRequest::query()->create([
        'company_id'              => $workflow->company_id,
        'workflow_id'             => $workflow->id,
        'requester_id'            => $requester->id,
        'subject_type'            => 'company',
        'subject_id'              => $workflow->company_id,
        'request_type'            => $workflow->request_type,
        'status'                  => 'pending',
        'current_step_sequence'   => 1,
        'submission_round'        => 1,
        'submitted_at'            => now(),
        'current_step_started_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->company = aflCompany();
    $this->hr = aflHr($this->company);
    $this->service = app(ApprovalFlowService::class);
});

it('lets HR of the company manage flows and nobody else', function (): void {
    $employee = aflUser($this->company);
    $otherCompany = aflCompany();

    expect($this->service->canManage($this->hr, $this->company->id))->toBeTrue()
        ->and($this->service->canManage($this->hr, $otherCompany->id))->toBeFalse()
        ->and($this->service->canManage($employee, $this->company->id))->toBeFalse();
});

it('changes who approves a step and keeps the step', function (): void {
    $workflow = aflWorkflow($this->company);
    $finance = Role::query()->firstOrCreate(['name' => 'afl finance', 'guard_name' => 'web']);
    $steps = aflSteps($workflow);
    $steps[1] = ['id' => $steps[1]['id'], 'name' => 'Finance', 'hierarchy_route' => null, 'approver_user_id' => null, 'approver_role_id' => $finance->id, 'required_approvals' => 2, 'sla_hours' => 48];

    $saved = $this->service->save($this->hr, $workflow, ['name' => 'Leave approval v2', 'steps' => $steps]);

    expect($saved->name)->toBe('Leave approval v2')
        ->and($saved->steps)->toHaveCount(2)
        ->and($saved->steps[1]->id)->toBe($workflow->steps[1]->id)
        ->and($saved->steps[1]->approver_role_id)->toBe($finance->id)
        ->and($saved->steps[1]->hierarchy_route)->toBeNull()
        ->and($saved->steps[1]->required_approvals)->toBe(2)
        ->and($saved->steps[1]->sla_hours)->toBe(48)
        ->and($this->service->summarise($saved->fresh('steps.approverRole')))->toBe('Line manager → Role: Afl finance');
});

it('needs at least one step and a name for every step', function (): void {
    $workflow = aflWorkflow($this->company);

    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => []]))->toThrow(InvalidArgumentException::class, 'at least one step');

    $steps = aflSteps($workflow);
    $steps[0]['name'] = ' ';
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $steps]))->toThrow(InvalidArgumentException::class, 'Step 1 needs a name');
});

it('allows exactly one kind of approver per step', function (): void {
    $workflow = aflWorkflow($this->company);
    $person = aflUser($this->company);

    $both = aflSteps($workflow);
    $both[0]['approver_user_id'] = $person->id;
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $both]))->toThrow(InvalidArgumentException::class, 'not several');

    $none = aflSteps($workflow);
    $none[0]['hierarchy_route'] = null;
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $none]))->toThrow(InvalidArgumentException::class, 'must say who approves');

    $unknown = aflSteps($workflow);
    $unknown[0]['hierarchy_route'] = 'the_ceo';
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $unknown]))->toThrow(InvalidArgumentException::class, 'not known');
});

it('refuses a person from another company, an inactive person and a built-in administrator role', function (): void {
    $workflow = aflWorkflow($this->company);
    $stranger = aflUser(aflCompany());
    $gone = aflUser($this->company);
    $gone->update(['is_active' => false]);
    $admin = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $superAdmin = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    foreach ([$stranger, $gone] as $user) {
        $steps = aflSteps($workflow);
        $steps[0] = ['id' => $steps[0]['id'], 'name' => 'Manager', 'hierarchy_route' => null, 'approver_user_id' => $user->id, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null];
        expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $steps]))->toThrow(InvalidArgumentException::class, 'cannot approve in this company');
    }

    foreach ([$admin, $superAdmin] as $role) {
        $steps = aflSteps($workflow);
        $steps[0] = ['id' => $steps[0]['id'], 'name' => 'Manager', 'hierarchy_route' => null, 'approver_user_id' => null, 'approver_role_id' => $role->id, 'required_approvals' => 1, 'sla_hours' => null];
        expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $steps]))->toThrow(InvalidArgumentException::class, 'cannot be an approver');
    }

    expect($workflow->fresh('steps')->steps[0]->hierarchy_route)->toBe('requester_manager');
});

it('accepts a named person of the company', function (): void {
    $workflow = aflWorkflow($this->company);
    $person = aflUser($this->company);
    $steps = aflSteps($workflow);
    $steps[0] = ['id' => $steps[0]['id'], 'name' => 'Director', 'hierarchy_route' => null, 'approver_user_id' => $person->id, 'approver_role_id' => null, 'required_approvals' => 5, 'sla_hours' => null];

    $saved = $this->service->save($this->hr, $workflow, ['name' => 'Leave approval', 'steps' => $steps]);

    expect($saved->steps[0]->approver_user_id)->toBe($person->id)
        ->and($saved->steps[0]->required_approvals)->toBe(1);
});

it('locks the structure while requests wait, but still allows changing an approver', function (): void {
    $workflow = aflWorkflow($this->company);
    $requester = aflUser($this->company);
    aflPending($workflow, $requester);
    aflPending($workflow, $requester);
    $person = aflUser($this->company);

    $steps = aflSteps($workflow);
    $removed = [$steps[0]];
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $removed]))
        ->toThrow(RuntimeException::class, 'There are 2 requests waiting on this flow. You can change who approves a step, but not add, remove or reorder steps until they are finished.');

    $added = [...$steps, ['id' => null, 'name' => 'Extra', 'hierarchy_route' => 'hr', 'approver_user_id' => null, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null]];
    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $added]))->toThrow(RuntimeException::class, 'waiting on this flow');

    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => array_reverse($steps)]))->toThrow(RuntimeException::class, 'waiting on this flow');

    $steps[1] = ['id' => $steps[1]['id'], 'name' => 'Chosen person', 'hierarchy_route' => null, 'approver_user_id' => $person->id, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => 24];
    $saved = $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $steps]);

    expect($saved->steps[1]->approver_user_id)->toBe($person->id)
        ->and($saved->steps[1]->sla_hours)->toBe(24)
        ->and($saved->steps)->toHaveCount(2);
});

it('says "is" for a single waiting request', function (): void {
    expect($this->service->lockMessage(1))->toBe('There is 1 request waiting on this flow. You can change who approves a step, but not add, remove or reorder steps until it is finished.');
});

it('adds, removes and reorders steps when nothing is waiting', function (): void {
    $workflow = aflWorkflow($this->company);
    $steps = aflSteps($workflow);
    $added = ['id' => null, 'name' => 'Team lead', 'hierarchy_route' => 'team_manager', 'approver_user_id' => null, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null];

    $saved = $this->service->save($this->hr, $workflow, ['name' => 'Leave approval', 'steps' => [$added, $steps[1], $steps[0]]]);

    expect($saved->steps->pluck('name')->all())->toBe(['Team lead', 'HR', 'Manager'])
        ->and($saved->steps->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($saved->steps[1]->id)->toBe($steps[1]['id']);

    $saved = $this->service->save($this->hr, $saved, ['name' => 'Leave approval', 'steps' => [aflSteps($saved)[1]]]);
    expect($saved->steps)->toHaveCount(1)->and($saved->steps[0]->name)->toBe('HR');
});

it('never removes a step that already decided a request', function (): void {
    $workflow = aflWorkflow($this->company);
    $requester = aflUser($this->company);
    $request = aflPending($workflow, $requester);
    $request->update(['status' => 'approved', 'current_step_sequence' => null]);
    ApprovalDecision::query()->create([
        'request_id' => $request->id, 'step_id' => $workflow->steps[0]->id, 'submission_round' => 1,
        'actor_id'   => $this->hr->id, 'decision' => 'approved', 'decided_at' => now(),
    ]);

    $steps = aflSteps($workflow);

    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => [$steps[1]]]))
        ->toThrow(RuntimeException::class, 'already approved or rejected');
    expect($workflow->steps()->count())->toBe(2);
});

it('cannot change the kind of request of an existing flow', function (): void {
    $workflow = aflWorkflow($this->company);

    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'request_type' => 'overtime', 'steps' => aflSteps($workflow)]))
        ->toThrow(InvalidArgumentException::class, 'cannot be changed');
    expect($workflow->fresh()->request_type)->toBe('leave_request');
});

it('creates a flow once per kind of request, in the actor\'s company', function (): void {
    $other = aflCompany();
    $steps = [['id' => null, 'name' => 'HR', 'hierarchy_route' => 'hr', 'approver_user_id' => null, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null]];

    expect($this->service->availableRequestTypes($this->company->id))->toHaveKey('overtime');

    $created = $this->service->save($this->hr, null, ['name' => 'Overtime', 'request_type' => 'overtime', 'company_id' => $other->id, 'steps' => $steps]);

    expect($created->company_id)->toBe($this->company->id)
        ->and($created->request_type)->toBe('overtime')
        ->and($created->is_active)->toBeTrue()
        ->and($this->service->availableRequestTypes($this->company->id))->not->toHaveKey('overtime')
        ->and($this->service->availableRequestTypes($other->id))->toHaveKey('overtime');

    expect(fn () => $this->service->save($this->hr, null, ['name' => 'Again', 'request_type' => 'overtime', 'steps' => $steps]))
        ->toThrow(InvalidArgumentException::class, 'already has a flow');
    expect(fn () => $this->service->save($this->hr, null, ['name' => 'Odd', 'request_type' => 'journal_posting', 'steps' => $steps]))
        ->toThrow(InvalidArgumentException::class, 'Choose what kind of request');
});

it('keeps HR of one company away from the flows of another', function (): void {
    $other = aflCompany();
    $foreign = aflWorkflow($other);
    $steps = aflSteps($foreign);

    expect(fn () => $this->service->save($this->hr, $foreign, ['name' => 'Hijacked', 'steps' => $steps]))
        ->toThrow(RuntimeException::class, 'cannot change');
    expect(fn () => $this->service->setActive($this->hr, $foreign, false))->toThrow(RuntimeException::class);
    expect($foreign->fresh()->name)->toBe('Leave approval')
        ->and($foreign->fresh()->is_active)->toBeTrue()
        ->and($this->service->flowsFor($this->company->id)->count())->toBe(0);
});

it('refuses a step id taken from another flow', function (): void {
    $workflow = aflWorkflow($this->company);
    $foreignStep = aflWorkflow(aflCompany())->steps[0];
    $steps = aflSteps($workflow);
    $steps[0]['id'] = $foreignStep->id;

    expect(fn () => $this->service->save($this->hr, $workflow, ['name' => 'X', 'steps' => $steps]))
        ->toThrow(InvalidArgumentException::class, 'does not belong');
    expect($foreignStep->fresh()->hierarchy_route)->toBe('requester_manager');
});

it('switches a flow off and on without deleting it', function (): void {
    $workflow = aflWorkflow($this->company);

    expect($this->service->setActive($this->hr, $workflow, false)->is_active)->toBeFalse()
        ->and($this->service->setActive($this->hr, $workflow->fresh(), true)->is_active)->toBeTrue()
        ->and(ApprovalWorkflow::query()->whereKey($workflow->id)->exists())->toBeTrue();
});

it('writes a structured audit line for each change', function (): void {
    Log::spy();
    $workflow = aflWorkflow($this->company);

    $this->service->setActive($this->hr, $workflow, false);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'approval_flow_changed'
        && $context['actor_id'] === $this->hr->id
        && $context['workflow_id'] === $workflow->id
        && $context['after'] === ['is_active' => false])->once();
});

it('shows the flow page to HR only', function (): void {
    aflActAs($this->hr);
    expect(ApprovalFlows::canAccess())->toBeTrue();

    aflActAs(aflUser($this->company));
    expect(ApprovalFlows::canAccess())->toBeFalse();
});

it('lists the company\'s flows with plain columns', function (): void {
    $workflow = aflWorkflow($this->company);
    aflPending($workflow, aflUser($this->company));
    $foreign = aflWorkflow(aflCompany());
    $claims = aflWorkflow($this->company, 'claim_tech');
    $accounting = aflWorkflow($this->company, 'journal_posting');
    aflActAs($this->hr);

    Livewire::test(ApprovalFlows::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$workflow, $claims])
        ->assertCanNotSeeTableRecords([$foreign, $accounting])
        ->assertSee('Leave request')
        ->assertSee('Line manager → HR')
        ->assertSee('On')
        ->assertSee('Waiting now')
        ->assertActionVisible('help');
});

it('edits a flow through the page', function (): void {
    $workflow = aflWorkflow($this->company);
    $steps = aflSteps($workflow);
    aflActAs($this->hr);

    $form = [
        'name'  => 'Leave, new rules',
        'steps' => [
            ['id' => $steps[0]['id'], 'name' => 'Department', 'kind' => 'department_manager', 'approver_user_id' => null, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => 12],
            ['id' => $steps[1]['id'], 'name' => 'HR', 'kind' => 'hr', 'approver_user_id' => null, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null],
        ],
    ];

    Livewire::test(ApprovalFlows::class)
        ->mountTableAction('edit', $workflow)
        ->set('mountedActions.0.data', $form)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $saved = $workflow->fresh('steps');
    expect($saved->name)->toBe('Leave, new rules')
        ->and($saved->steps[0]->hierarchy_route)->toBe('department_manager')
        ->and($saved->steps[0]->sla_hours)->toBe(12);
});

it('tells HR plainly why a structure change is refused on the page', function (): void {
    $workflow = aflWorkflow($this->company);
    aflPending($workflow, aflUser($this->company));
    $steps = aflSteps($workflow);
    aflActAs($this->hr);

    Livewire::test(ApprovalFlows::class)
        ->mountTableAction('edit', $workflow)
        ->set('mountedActions.0.data', [
            'name'  => 'Leave approval',
            'steps' => [['id' => $steps[0]['id'], 'name' => 'Manager', 'kind' => 'requester_manager', 'required_approvals' => 1]],
        ])
        ->callMountedTableAction()
        ->assertNotified('Not saved');

    expect($workflow->steps()->count())->toBe(2);
});

it('creates a flow through the page and switches it off', function (): void {
    aflActAs($this->hr);

    $page = Livewire::test(ApprovalFlows::class)
        ->mountAction('create')
        ->set('mountedActions.0.data', [
            'request_type' => 'overtime',
            'name'         => 'Overtime approval',
            'steps'        => [['id' => null, 'name' => 'Manager', 'kind' => 'requester_manager', 'required_approvals' => 1]],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $flow = ApprovalWorkflow::query()->where('company_id', $this->company->id)->where('request_type', 'overtime')->firstOrFail();
    expect($flow->steps)->toHaveCount(1)->and($flow->steps[0]->hierarchy_route)->toBe('requester_manager');

    $page->callTableAction('switch', $flow);
    expect($flow->fresh()->is_active)->toBeFalse();
});

it('keeps the engine working with a flow edited by HR', function (): void {
    $workflow = aflWorkflow($this->company);
    $person = aflUser($this->company);
    $steps = aflSteps($workflow);
    $steps[0] = ['id' => $steps[0]['id'], 'name' => 'Named', 'hierarchy_route' => null, 'approver_user_id' => $person->id, 'approver_role_id' => null, 'required_approvals' => 1, 'sla_hours' => null];
    $this->service->save($this->hr, $workflow, ['name' => 'Leave approval', 'steps' => $steps]);

    $request = app(ApprovalEngine::class)->submit($this->company, aflUser($this->company), 'leave_request', null, ['company_id' => $this->company->id]);

    expect(app(ApprovalEngine::class)->canAct($request, $person))->toBeTrue();
});
