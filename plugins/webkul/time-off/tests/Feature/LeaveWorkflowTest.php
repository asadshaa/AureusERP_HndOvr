<?php

/**
 * Section 3 ("IMPLEMENTATION SECTION 3 -- LEAVE / TIME OFF") -- exercises
 * the *existing* Time Off plugin end to end: LeaveWorkflowSeeder's
 * provisioning of the canonical leave types + the leave_request approval
 * workflow, then the real Employee -> Submit -> Line Manager Review ->
 * Approved/Rejected path through LeaveApprovalService + ApprovalEngine
 * (no parallel/new leave logic introduced), plus the validation rules
 * TimeOffHelper already enforces on the create form (company scoping,
 * overlap, balance).
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Database\Seeders\LeaveWorkflowSeeder;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\CreateTimeOff;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Traits\TimeOffHelper;

function leaveWorkflowFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(LeaveWorkflowSeeder::class)->run();

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Employee']);

    $leaveType = LeaveType::query()->where('company_id', $company->id)->where('name', 'Annual Leave')->firstOrFail();

    LeaveAllocation::query()->create([
        'holiday_status_id'    => $leaveType->id,
        'employee_id'          => $employee->id,
        'employee_company_id'  => $company->id,
        'creator_id'           => $managerUser->id,
        'name'                 => 'Annual Leave Allocation',
        'state'                => State::VALIDATE_TWO,
        'allocation_type'      => 'regular',
        'date_to'              => now()->endOfYear(),
        'number_of_days'       => 20,
    ]);

    return compact('company', 'managerUser', 'manager', 'employeeUser', 'employee', 'leaveType');
}

function grantTimeOffFormAccess(User $user): void
{
    $role = Role::query()->firstOrCreate(['name' => 'leave-workflow-test-form-role-'.$user->id, 'guard_name' => 'web']);
    foreach (['view_any_time_off_time::off', 'view_time_off_time::off', 'create_time_off_time::off'] as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function availableBalance(Employee $employee, LeaveType $leaveType): float
{
    $totalAllocated = LeaveAllocation::where('employee_id', $employee->id)
        ->where('holiday_status_id', $leaveType->id)
        ->where('state', State::VALIDATE_TWO->value)
        ->sum('number_of_days');

    $totalTaken = Leave::where('employee_id', $employee->id)
        ->where('holiday_status_id', $leaveType->id)
        ->where('state', '!=', State::REFUSE->value)
        ->sum('number_of_days');

    return round($totalAllocated - $totalTaken, 1);
}

// ---------------------------------------------------------------------
// Leave types + workflow provisioning.
// ---------------------------------------------------------------------
it('provisions Annual/Casual/Sick Leave and a line-manager leave_request workflow per company, idempotently', function () {
    $company = Company::factory()->create(['is_active' => true]);

    app(LeaveWorkflowSeeder::class)->run();
    app(LeaveWorkflowSeeder::class)->run(); // re-run: must not duplicate anything

    $types = LeaveType::query()->where('company_id', $company->id)->pluck('name')->sort()->values()->all();
    expect($types)->toBe(['Annual Leave', 'Casual Leave', 'Sick Leave']);

    $workflow = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'leave_request')->get();
    expect($workflow)->toHaveCount(1);

    $steps = $workflow->first()->steps;
    expect($steps)->toHaveCount(1)
        ->and($steps->first()->hierarchy_route)->toBe('requester_manager')
        ->and($steps->first()->required_approvals)->toBe(1);
});

// ---------------------------------------------------------------------
// Full approve path.
// ---------------------------------------------------------------------
it('submits a leave request, routes it to the line manager, and approving retains the decision, updates state, and keeps the balance reduced', function () {
    $f = leaveWorkflowFixture();

    $leave = Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $f['leaveType']->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => now()->addDays(5)->toDateString(),
        'request_date_to'     => now()->addDays(9)->toDateString(),
        'date_from'           => now()->addDays(5)->toDateString(),
        'date_to'             => now()->addDays(9)->toDateString(),
        'number_of_days'      => 5,
    ]);

    expect(availableBalance($f['employee'], $f['leaveType']))->toBe(15.0);

    $service = app(LeaveApprovalService::class);
    $approval = $service->submit($leave, $f['employeeUser']);

    expect($approval->status)->toBe('pending')
        ->and($approval->workflow->request_type)->toBe('leave_request');

    $engine = app(ApprovalEngine::class);
    expect($engine->canAct($approval, $f['employeeUser']))->toBeFalse()
        ->and($engine->canAct($approval, $f['managerUser']))->toBeTrue();

    $leave = $service->approve($leave, $f['managerUser'], 'Looks good');

    expect($leave->state)->toBe(State::VALIDATE_TWO)
        ->and($leave->approved_at)->not->toBeNull()
        ->and($leave->rejected_at)->toBeNull()
        ->and($leave->first_approver_id)->toBe($f['manager']->id);

    $leave->load('approvalRequest.decisions');
    expect($leave->approvalRequest->status)->toBe('approved')
        ->and($leave->approvalRequest->decisions)->toHaveCount(1)
        ->and($leave->approvalRequest->decisions->first()->decision)->toBe('approved')
        ->and($leave->approvalRequest->decisions->first()->reason)->toBe('Looks good');

    // VALIDATE_TWO still counts toward "taken" -- balance stays reduced after approval.
    expect(availableBalance($f['employee'], $f['leaveType']))->toBe(15.0);
});

// ---------------------------------------------------------------------
// Full reject path.
// ---------------------------------------------------------------------
it('rejecting a leave request preserves the decision and reason, leaves it refused, and does not deduct the balance', function () {
    $f = leaveWorkflowFixture();

    $leave = Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $f['leaveType']->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => now()->addDays(5)->toDateString(),
        'request_date_to'     => now()->addDays(9)->toDateString(),
        'date_from'           => now()->addDays(5)->toDateString(),
        'date_to'             => now()->addDays(9)->toDateString(),
        'number_of_days'      => 5,
    ]);

    $service = app(LeaveApprovalService::class);
    $service->submit($leave, $f['employeeUser']);

    $leave = $service->reject($leave, $f['managerUser'], 'Team is short-staffed that week');

    expect($leave->state)->toBe(State::REFUSE)
        ->and($leave->rejection_reason)->toBe('Team is short-staffed that week')
        ->and($leave->approved_at)->toBeNull()
        ->and($leave->rejected_at)->not->toBeNull();

    $leave->load('approvalRequest.decisions');
    expect($leave->approvalRequest->status)->toBe('rejected')
        ->and($leave->approvalRequest->decisions)->toHaveCount(1)
        ->and($leave->approvalRequest->decisions->first()->decision)->toBe('rejected');

    // REFUSE is excluded from "taken" -- the full allocation is available again.
    expect(availableBalance($f['employee'], $f['leaveType']))->toBe(20.0);
});

// ---------------------------------------------------------------------
// Authorization: only the requester (or hr_approve_leave) may submit;
// only the resolved approver (here, the line manager) may act.
// ---------------------------------------------------------------------
it('refuses to submit leave on behalf of another employee without hr_approve_leave permission', function () {
    $f = leaveWorkflowFixture();
    $bystander = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);

    $leave = Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $f['leaveType']->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => now()->addDays(5)->toDateString(),
        'number_of_days'      => 1,
    ]);

    expect(fn () => app(LeaveApprovalService::class)->submit($leave, $bystander))
        ->toThrow(RuntimeException::class, 'A user can only submit leave for their own HR hierarchy');
});

it('refuses to let an unrelated user approve a leave request that is not theirs to act on', function () {
    $f = leaveWorkflowFixture();
    $stranger = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $stranger->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    $leave = Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $f['leaveType']->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => now()->addDays(5)->toDateString(),
        'number_of_days'      => 1,
    ]);

    app(LeaveApprovalService::class)->submit($leave, $f['employeeUser']);

    expect(fn () => app(LeaveApprovalService::class)->approve($leave, $stranger))
        ->toThrow(RuntimeException::class, 'not an approver');
});

// ---------------------------------------------------------------------
// Validation the create form already performs (company, overlap, balance).
// ---------------------------------------------------------------------
it('refuses to create a leave request for an employee outside the acting user\'s active company', function () {
    $f = leaveWorkflowFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $outsider = Employee::query()->create(['company_id' => $otherCompany->id, 'name' => 'Outsider']);

    grantTimeOffFormAccess($f['managerUser']);
    Auth::login($f['managerUser']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['managerUser']);

    $countBefore = Leave::query()->count();

    // The employee_id Select's own relationship query is scoped to
    // Auth::user()->default_company_id (TimeOffHelper::getFormSchema()), so a
    // cross-company id submitted here never resolves to a selected option in
    // the first place -- Filament silently drops it and the field's
    // ->required() rule then blocks the submission. No Leave row is created;
    // updateEmployeeAndCompanyData()'s RuntimeException guard is a second,
    // deeper layer that only fires if that first (server-side, not just
    // client-side) scoping were ever bypassed.
    Livewire::test(CreateTimeOff::class)
        ->fillForm([
            'employee_id'       => $outsider->id,
            'holiday_status_id' => $f['leaveType']->id,
            'request_date_from' => now()->addDays(1)->toDateString(),
            'request_date_to'   => now()->addDays(2)->toDateString(),
        ])
        ->call('create');

    expect(Leave::query()->count())->toBe($countBefore);
});

it('the deeper company guard (updateEmployeeAndCompanyData) itself throws if a cross-company employee_id ever reaches it directly', function () {
    $f = leaveWorkflowFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $outsider = Employee::query()->create(['company_id' => $otherCompany->id, 'name' => 'Outsider']);

    Auth::login($f['managerUser']);

    $helper = new class
    {
        use TimeOffHelper;
    };

    expect(fn () => $helper->mutateTimeOffData([
        'employee_id'       => $outsider->id,
        'holiday_status_id' => $f['leaveType']->id,
        'request_date_from' => now()->addDays(1)->toDateString(),
        'request_date_to'   => now()->addDays(2)->toDateString(),
    ]))->toThrow(RuntimeException::class, 'outside the active company');
});

it('halts creation when the requested dates overlap an existing leave for the same employee', function () {
    $f = leaveWorkflowFixture();

    Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $f['leaveType']->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => now()->addDays(5)->toDateString(),
        'request_date_to'     => now()->addDays(9)->toDateString(),
        'date_from'           => now()->addDays(5)->toDateString(),
        'date_to'             => now()->addDays(9)->toDateString(),
        'number_of_days'      => 5,
    ]);

    grantTimeOffFormAccess($f['managerUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['managerUser']);

    $countBefore = Leave::query()->count();

    Livewire::test(CreateTimeOff::class)
        ->fillForm([
            'employee_id'       => $f['employee']->id,
            'holiday_status_id' => $f['leaveType']->id,
            'request_date_from' => now()->addDays(7)->toDateString(),
            'request_date_to'   => now()->addDays(8)->toDateString(),
        ])
        ->call('create');

    // halt() aborts the Livewire action without throwing -- assert no second row was created.
    expect(Leave::query()->count())->toBe($countBefore);
});

it('halts creation when the requested days exceed the employee\'s available balance', function () {
    $f = leaveWorkflowFixture();

    grantTimeOffFormAccess($f['managerUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['managerUser']);

    $countBefore = Leave::query()->count();

    Livewire::test(CreateTimeOff::class)
        ->fillForm([
            'employee_id'       => $f['employee']->id,
            'holiday_status_id' => $f['leaveType']->id,
            // 20 allocated; this spans far more than 20 working days.
            'request_date_from' => now()->addDays(30)->toDateString(),
            'request_date_to'   => now()->addDays(90)->toDateString(),
        ])
        ->call('create');

    expect(Leave::query()->count())->toBe($countBefore);
});
