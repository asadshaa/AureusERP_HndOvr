<?php

/**
 * HR parity plan 2.7 -- "Return to requester" for leave: a current-step
 * approver returns the leave with a comment; it goes back to an editable
 * state without a new Leave state (state "refuse" + approval request
 * "returned"), is not counted as pending in the balance while returned, is
 * edited and resubmitted by the employee, and restarts at the first step.
 * The employee can also withdraw it while returned.
 */

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\TimeOffRelationManager;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource\Pages\EditMyTimeOff;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\LeaveCancellationService;

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    config(['attendance.notifications.leave_decision' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function lrtUser(Company $company, string $name, array $permissions = []): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($permissions !== []) {
        $role = Role::query()->firstOrCreate(['name' => 'lrt_'.md5(implode(',', $permissions)).'_'.$company->id, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    return $user;
}

/** Line manager, then HR, for leave; Annual 12 / Sick 8 / Casual 5 for 2026 (hire-time allocation). */
function lrtFixture(): array
{
    $company = Company::factory()->create(['name' => 'Return Co '.uniqid(), 'is_active' => true]);

    foreach (['Annual Leave', 'Casual Leave', 'Sick Leave'] as $typeName) {
        LeaveType::query()->firstOrCreate(['company_id' => $company->id, 'name' => $typeName], [
            'requires_allocation'        => 'yes',
            'request_unit'               => 'day',
            'time_type'                  => 'leave',
            'leave_validation_type'      => 'manager',
            'allocation_validation_type' => 'manager',
            'employee_requests'          => 'no',
            'is_active'                  => true,
        ]);
    }

    $workflow = ApprovalWorkflow::query()->firstOrCreate(
        ['company_id' => $company->id, 'request_type' => 'leave_request'],
        ['name' => 'Leave Request Approval', 'is_active' => true],
    );
    ApprovalStep::query()->where('workflow_id', $workflow->id)->delete();
    ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => 2, 'name' => 'HR Review', 'hierarchy_route' => 'hr', 'required_approvals' => 1]);

    $managerUser = lrtUser($company, 'Sana Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Sana Manager', 'is_active' => true]);

    $employeeUser = lrtUser($company, 'Ali Employee', ['view_any_time_off_my::time::off', 'view_time_off_my::time::off', 'update_time_off_my::time::off']);
    $employee = Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Ali Employee', 'is_active' => true,
    ]);

    $hrUser = lrtUser($company, 'Hira HR', ['hr_approve_leave', 'hr_view_all_records']);

    $otherCompany = Company::factory()->create(['name' => 'Other Co '.uniqid(), 'is_active' => true]);
    $otherHr = lrtUser($otherCompany, 'Other HR', ['hr_approve_leave', 'hr_view_all_records']);

    return compact('company', 'managerUser', 'employeeUser', 'employee', 'hrUser', 'otherHr');
}

function lrtLeave(array $f, string $from, string $to, float $days): Leave
{
    $type = LeaveType::query()->where('company_id', $f['company']->id)->where('name', 'Casual Leave')->firstOrFail();

    return Leave::query()->create([
        'user_id'             => $f['employeeUser']->id,
        'holiday_status_id'   => $type->id,
        'employee_id'         => $f['employee']->id,
        'employee_company_id' => $f['company']->id,
        'company_id'          => $f['company']->id,
        'creator_id'          => $f['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => $from,
        'request_date_to'     => $to,
        'date_from'           => $from,
        'date_to'             => $to,
        'number_of_days'      => $days,
    ]);
}

function lrtBalance(array $f): array
{
    $type = LeaveType::query()->where('company_id', $f['company']->id)->where('name', 'Casual Leave')->firstOrFail();

    return app(LeaveBalanceService::class)->forEmployee($f['employee'], $type, 2026);
}

it('returns a leave for changes: out of the balance while returned, edited, resubmitted from step 1', function () {
    $f = lrtFixture();
    $service = app(LeaveApprovalService::class);
    $leave = lrtLeave($f, '2026-11-02', '2026-11-03', 2);
    $approval = $service->submit($leave, $f['employeeUser']);

    $service->approve($leave->fresh(), $f['managerUser'], 'OK');
    expect($leave->fresh()->state)->toBe(State::VALIDATE_ONE)
        ->and(lrtBalance($f))->toMatchArray(['pending' => 2.0, 'remaining' => 3.0]);

    // HR (step 2) returns it.
    $returned = $service->returnToRequester($leave->fresh(), $f['hrUser'], 'Take Wednesday too, the 4th is a handover day');

    expect($returned->state)->toBe(State::REFUSE)
        ->and(LeaveApprovalService::isReturned($returned))->toBeTrue()
        ->and($returned->rejected_at)->toBeNull()
        ->and($returned->approvalRequest->status)->toBe('returned')
        ->and(lrtBalance($f))->toMatchArray(['pending' => 0.0, 'taken' => 0.0, 'remaining' => 5.0]);

    // The employee is told, with a link to their own leave.
    $notice = $f['employeeUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_returned_'));
    expect($notice->data['body'])->toContain('handover day')
        ->and(collect($notice->data['actions'])->pluck('url')->first())->toContain('my-time-offs/'.$leave->id);

    // Edited on My Time Off: it stays returned (out of the balance) until resubmitted.
    Auth::login($f['employeeUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Livewire::test(EditMyTimeOff::class, ['record' => $leave->getRouteKey()])
        ->fillForm(['request_date_to' => '2026-11-04'])
        ->call('save')
        ->assertHasNoFormErrors();

    $edited = $leave->fresh(['approvalRequest']);
    expect((float) $edited->number_of_days)->toBe(3.0)
        ->and($edited->state)->toBe(State::REFUSE)
        ->and(LeaveApprovalService::isReturned($edited))->toBeTrue()
        ->and(lrtBalance($f)['pending'])->toBe(0.0);

    // Only the employee who submitted it resubmits.
    foreach ([$f['managerUser'], $f['hrUser']] as $notRequester) {
        expect($service->canResubmit($edited, $notRequester))->toBeFalse()
            ->and(fn () => $service->submit($edited->fresh(), $notRequester))->toThrow(RuntimeException::class);
    }
    expect($edited->fresh()->state)->toBe(State::REFUSE);

    $resubmitted = $service->submit($edited->fresh(), $f['employeeUser']);
    $leave = $leave->fresh(['approvalRequest.decisions']);

    expect($resubmitted->id)->toBe($approval->id)
        ->and($resubmitted->submission_round)->toBe(2)
        ->and($resubmitted->current_step_sequence)->toBe(1)
        ->and((float) $resubmitted->context['days'])->toBe(3.0)
        ->and($leave->state)->toBe(State::CONFIRM)
        ->and($leave->first_approver_id)->toBeNull()
        ->and(lrtBalance($f))->toMatchArray(['pending' => 3.0, 'remaining' => 2.0])
        ->and($leave->approvalRequest->decisions->firstWhere('decision', 'resubmitted')->new_values)
        ->toMatchArray(['request_date_to' => '2026-11-04', 'number_of_days' => '3']);

    // A restarted step 1: the line manager approves again, then HR.
    $service->approve($leave->fresh(), $f['managerUser'], 'Fine');
    expect($leave->fresh()->state)->toBe(State::VALIDATE_ONE);
    $approved = $service->approve($leave->fresh(), $f['hrUser'], 'Approved');

    expect($approved->state)->toBe(State::VALIDATE_TWO)
        ->and(lrtBalance($f))->toMatchArray(['taken' => 3.0, 'pending' => 0.0, 'remaining' => 2.0]);
});

it('lets only a current-step approver of the leave company return it, with a comment', function () {
    $f = lrtFixture();
    $service = app(LeaveApprovalService::class);
    $leave = lrtLeave($f, '2026-11-09', '2026-11-09', 1);
    $service->submit($leave, $f['employeeUser']);

    foreach ([$f['hrUser'], $f['employeeUser'], $f['otherHr']] as $notApprover) {
        expect(fn () => $service->returnToRequester($leave->fresh(), $notApprover, 'No'))->toThrow(RuntimeException::class);
    }
    expect(fn () => $service->returnToRequester($leave->fresh(), $f['managerUser'], ''))->toThrow(RuntimeException::class)
        ->and($leave->fresh()->state)->toBe(State::CONFIRM)
        ->and(lrtBalance($f)['pending'])->toBe(1.0);

    $service->returnToRequester($leave->fresh(), $f['managerUser'], 'Pick another day');
    expect(LeaveApprovalService::isReturned($leave->fresh()))->toBeTrue();
});

it('lets the employee withdraw a returned leave', function () {
    $f = lrtFixture();
    $service = app(LeaveApprovalService::class);
    $leave = lrtLeave($f, '2026-11-10', '2026-11-10', 1);
    $service->submit($leave, $f['employeeUser']);
    $service->returnToRequester($leave->fresh(), $f['managerUser'], 'Pick another day');

    $cancellation = app(LeaveCancellationService::class);
    expect($cancellation->canWithdraw($leave->fresh(), $f['managerUser']))->toBeFalse()
        ->and($cancellation->canWithdraw($leave->fresh(), $f['employeeUser']))->toBeTrue();

    $withdrawn = $cancellation->withdraw($leave->fresh(), $f['employeeUser'], 'Not needed');

    expect($withdrawn->state)->toBe(State::CANCEL)
        ->and($withdrawn->approvalRequest->status)->toBe('withdrawn')
        ->and(LeaveApprovalService::isReturned($withdrawn))->toBeFalse()
        ->and(lrtBalance($f)['pending'])->toBe(0.0);
});

it('shows a returned leave as "Returned for changes", not refused, on the employee dashboard and the employee Time Off tab', function () {
    $f = lrtFixture();
    $service = app(LeaveApprovalService::class);
    $returned = lrtLeave($f, '2026-11-11', '2026-11-11', 1);
    $service->submit($returned, $f['employeeUser']);
    $service->returnToRequester($returned->fresh(), $f['managerUser'], 'Pick another day');
    $refused = lrtLeave($f, '2026-11-12', '2026-11-12', 1);
    $service->submit($refused, $f['employeeUser']);
    $service->reject($refused->fresh(), $f['managerUser'], 'No');

    $this->actingAs($f['employeeUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(EmployeeDashboardOverviewWidget::class)
        ->assertSee('Returned for changes')
        ->assertSee('Rejected');

    Livewire::test(TimeOffRelationManager::class, ['ownerRecord' => $f['employee'], 'pageClass' => ViewEmployee::class])
        ->assertTableColumnFormattedStateSet('state', 'Returned for changes', $returned)
        ->assertTableColumnFormattedStateSet('state', 'Refused', $refused);
});
