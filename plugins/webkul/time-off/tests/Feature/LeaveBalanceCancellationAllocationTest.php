<?php

/**
 * Leave balance on the request (LeaveBalanceService, enforced on the Leave
 * model) under the 25-day policy (Annual 12, Sick 8, Casual 5), Half day not
 * counting as leave, withdraw / cancellation (LeaveCancellationService), the
 * hire-time and yearly allocation with proration and carry-forward
 * (AllocateDefaultLeaveOnEmployeeCreated, YearlyLeaveAllocationService,
 * time-off:yearly-allocation), company provisioning (LeaveWorkflowSeeder,
 * Company::created), HR notices of a leave without a line manager
 * (LeaveApprovalService) and the "Who's on leave" month list
 * (LeaveCalendarService).
 */

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Seeders\LeaveWorkflowSeeder;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource\Pages\CreateMyTimeOff;
use Webkul\TimeOff\Filament\Pages\LeaveCalendar;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\LeaveCalendarService;
use Webkul\TimeOff\Services\LeaveCancellationService;
use Webkul\TimeOff\Services\YearlyLeaveAllocationService;

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    config(['attendance.notifications.leave_decision' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function lbcUser(Company $company, string $name, array $permissions = []): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($permissions !== []) {
        $role = Role::query()->firstOrCreate(['name' => 'lbc_hr_'.$company->id, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    return $user;
}

/** @return array{company: Company, managerUser: User, employeeUser: User, employee: Employee, hrUser: User, department: Department} */
function lbcFixture(string $name = 'Balance Co'): array
{
    // Company::created provisions Half day / Short leave / On Duty and Annual / Sick / Casual Leave
    // with their yearly allocation policies; the loop below only makes sure the latter exist.
    $company = Company::factory()->create(['name' => $name.' '.uniqid(), 'is_active' => true]);

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
    ApprovalStep::query()->firstOrCreate(
        ['workflow_id' => $workflow->id, 'sequence' => 1],
        ['name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1],
    );
    // Company::created also adds the HR Review step; these tests approve with the line manager only.
    ApprovalStep::query()->where('workflow_id', $workflow->id)->where('sequence', '>', 1)->delete();

    $department = Department::query()->create(['name' => 'Ops '.uniqid(), 'company_id' => $company->id]);

    $managerUser = lbcUser($company, 'Sana Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Sana Manager', 'is_active' => true]);

    $employeeUser = lbcUser($company, 'Ali Employee');
    // Created now (2026), no joining date: the hire-time listener allocates Annual 12, Sick 8, Casual 5 for 2026.
    $employee = Employee::query()->create([
        'company_id'    => $company->id,
        'user_id'       => $employeeUser->id,
        'parent_id'     => $manager->id,
        'department_id' => $department->id,
        'name'          => 'Ali Employee',
        'is_active'     => true,
    ]);

    $hrUser = lbcUser($company, 'Hira HR', ['hr_approve_leave', 'hr_view_all_records']);

    return compact('company', 'managerUser', 'employeeUser', 'employee', 'hrUser', 'department');
}

function lbcType(array $fixture, string $name): LeaveType
{
    return LeaveType::query()->where('company_id', $fixture['company']->id)->where('name', $name)->firstOrFail();
}

function lbcLeave(array $fixture, string $typeName, string $from, ?string $to = null, float $days = 1, array $attributes = [], ?Employee $employee = null): Leave
{
    $employee ??= $fixture['employee'];

    return Leave::query()->create($attributes + [
        'user_id'             => $employee->user_id,
        'holiday_status_id'   => lbcType($fixture, $typeName)->id,
        'employee_id'         => $employee->id,
        'employee_company_id' => $employee->company_id,
        'company_id'          => $employee->company_id,
        'department_id'       => $employee->department_id,
        'creator_id'          => $employee->user_id,
        'state'               => State::CONFIRM,
        'request_date_from'   => $from,
        'request_date_to'     => $to ?? $from,
        'date_from'           => $from,
        'date_to'             => $to ?? $from,
        'number_of_days'      => $days,
    ]);
}

function lbcApprove(array $fixture, Leave $leave): Leave
{
    $service = app(LeaveApprovalService::class);
    $service->submit($leave, $fixture['employeeUser']);

    return $service->approve($leave->fresh(), $fixture['managerUser'], 'Fine');
}

function lbcBalance(array $fixture, string $typeName, ?int $year = 2026): array
{
    return app(LeaveBalanceService::class)->forEmployee($fixture['employee'], lbcType($fixture, $typeName), $year);
}

/** @return array<int, string> notification titles of one kind (idempotency key prefix) */
function lbcNotices(User $user, string $prefix): array
{
    return $user->notifications()->get()
        ->filter(fn ($notification): bool => str_starts_with((string) ($notification->data['viewData']['idempotency_key'] ?? ''), $prefix))
        ->map(fn ($notification): string => (string) $notification->data['title'])
        ->values()
        ->all();
}

it('shows available, taken, pending and remaining days of the allocation year', function () {
    $f = lbcFixture();
    $service = app(LeaveBalanceService::class);

    expect($service->summary($f['employee'], lbcType($f, 'Casual Leave'), 2026))
        ->toBe('Casual Leave 2026: available 5, taken 0, pending 0, remaining 5 day(s).')
        ->and(lbcBalance($f, 'Annual Leave')['allocated'])->toBe(12.0)
        ->and(lbcBalance($f, 'Sick Leave')['allocated'])->toBe(8.0);

    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-02', '2026-11-03', 2));
    lbcLeave($f, 'Casual Leave', '2026-11-10', null, 1);

    expect(lbcBalance($f, 'Casual Leave'))->toMatchArray(['allocated' => 5.0, 'taken' => 2.0, 'pending' => 1.0, 'remaining' => 2.0])
        ->and($service->summary($f['employee'], lbcType($f, 'Casual Leave'), 2026))
        ->toBe('Casual Leave 2026: available 5, taken 2, pending 1, remaining 2 day(s).')
        // Next year has no allocation yet: nothing taken there either.
        ->and(lbcBalance($f, 'Casual Leave', 2027))->toMatchArray(['allocated' => 0.0, 'taken' => 0.0, 'pending' => 0.0]);
});

it('refuses on save a request above the remaining balance, whatever path writes it', function () {
    $f = lbcFixture();

    lbcLeave($f, 'Casual Leave', '2026-11-02', '2026-11-03', 2);

    expect(fn () => lbcLeave($f, 'Casual Leave', '2026-11-16', '2026-11-19', 4))
        ->toThrow(ValidationException::class, 'Not enough Casual Leave balance for 2026: 3 day(s) remaining');

    // Exactly the remaining 3 days fits.
    expect(lbcLeave($f, 'Casual Leave', '2026-11-16', '2026-11-18', 3)->exists)->toBeTrue();

    // Enlarging an existing request is checked too.
    $leave = Leave::query()->where('employee_id', $f['employee']->id)->where('number_of_days', 3)->firstOrFail();
    expect(fn () => $leave->update(['number_of_days' => 4]))->toThrow(ValidationException::class);
});

it('allows a negative balance only down to the leave type limit', function () {
    $f = lbcFixture();
    lbcType($f, 'Sick Leave')->update(['allows_negative' => true, 'max_allowed_negative' => 2]);

    // Sick Leave 8: all of it, then 2 more (down to -2).
    expect(lbcLeave($f, 'Sick Leave', '2026-11-02', '2026-11-11', 8)->exists)->toBeTrue()
        ->and(lbcLeave($f, 'Sick Leave', '2026-11-12', '2026-11-13', 2)->exists)->toBeTrue();

    // Half a day of Sick Leave (not the Half day type) takes 0.5 Sick day: below the limit.
    expect(fn () => lbcLeave($f, 'Sick Leave', '2026-11-16', null, 0.5, ['request_unit_half' => true]))
        ->toThrow(ValidationException::class, 'Not enough Sick Leave balance');
});

it('refuses a type that requires an allocation when there is none', function () {
    $f = lbcFixture();
    LeaveAllocation::query()->where('employee_id', $f['employee']->id)->delete();

    expect(fn () => lbcLeave($f, 'Annual Leave', '2026-11-02'))
        ->toThrow(ValidationException::class, 'There is no approved Annual Leave allocation for 2026');
});

it('does not count a Half day as leave: nothing deducted, never refused for balance', function () {
    $f = lbcFixture();
    $halfDay = lbcType($f, 'Half day');
    $service = app(LeaveBalanceService::class);

    expect($halfDay->requires_allocation)->toBe('no')
        ->and($service->summary($f['employee'], $halfDay, 2026))->toBe('Half day does not count as leave: no balance is deducted.')
        ->and(lbcBalance($f, 'Half day'))->toMatchArray(['tracked' => false, 'taken' => 0.0, 'pending' => 0.0]);

    // Casual Leave used up.
    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-09', '2026-11-13', 5));
    expect(lbcBalance($f, 'Casual Leave')['remaining'])->toBe(0.0);

    lbcApprove($f, lbcLeave($f, 'Half day', '2026-11-04', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'morning']));
    lbcLeave($f, 'Half day', '2026-11-05', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'afternoon']);

    expect(lbcBalance($f, 'Casual Leave'))->toMatchArray(['allocated' => 5.0, 'taken' => 5.0, 'pending' => 0.0, 'remaining' => 0.0])
        ->and(lbcBalance($f, 'Annual Leave'))->toMatchArray(['taken' => 0.0, 'pending' => 0.0, 'remaining' => 12.0])
        ->and(lbcBalance($f, 'Sick Leave'))->toMatchArray(['taken' => 0.0, 'pending' => 0.0, 'remaining' => 8.0]);

    // Not even without any allocation, nor if the type were marked as requiring one.
    LeaveAllocation::query()->where('employee_id', $f['employee']->id)->delete();
    $halfDay->update(['requires_allocation' => 'yes']);

    expect(lbcLeave($f, 'Half day', '2026-11-18', null, 0.5, ['request_unit_half' => true])->exists)->toBeTrue()
        ->and($service->refusal($f['employee'], $halfDay->fresh(), 0.5, '2026-11-19'))->toBeNull();
});

it('still covers half the day in the attendance report for an approved Half day that deducted nothing', function () {
    config([
        'attendance.monthly_report.office_start'           => '10:00',
        'attendance.monthly_report.office_end'             => '18:00',
        'attendance.monthly_report.daily_required_minutes' => 480,
        'attendance.monthly_report.timezone'               => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'           => [6, 7],
    ]);
    $f = lbcFixture();

    lbcApprove($f, lbcLeave($f, 'Half day', '2026-10-27', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'morning']));
    lbcApprove($f, lbcLeave($f, 'Half day', '2026-10-26', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'afternoon']));

    $report = app(MonthlyAttendanceReport::class)->forEmployee($f['employee'], (int) $f['company']->id, '2026-10');
    $rows = collect($report['rows'])->keyBy('date');

    expect(lbcBalance($f, 'Casual Leave')['remaining'])->toBe(5.0)
        ->and(lbcBalance($f, 'Annual Leave')['remaining'])->toBe(12.0)
        ->and($rows['2026-10-27']['type'])->toBe('working')
        ->and($rows['2026-10-27']['required_seconds'])->toBe(4 * 3600)
        ->and($rows['2026-10-27']['leave_days'])->toBe(0.5)
        ->and($rows['2026-10-27']['remarks'])->toContain('Half day - first half')
        ->and($rows['2026-10-26']['required_seconds'])->toBe(4 * 3600)
        ->and($rows['2026-10-26']['remarks'])->toContain('Half day - second half')
        // A day without leave still needs the full day.
        ->and($rows['2026-10-23']['required_seconds'])->toBe(8 * 3600)
        ->and($report['summary']['leave_days'])->toBe(1.0);
});

it('deducts nothing for Short leave, which needs no allocation', function () {
    $f = lbcFixture();

    expect(app(LeaveBalanceService::class)->summary($f['employee'], lbcType($f, 'Short leave'), 2026))
        ->toBe('Short leave needs no allocation: no balance is deducted.')
        ->and(lbcLeave($f, 'Short leave', '2026-11-04', null, 0.25, ['request_unit_hours' => true, 'request_hour_from' => 14, 'request_hour_to' => 16])->exists)->toBeTrue();
});

it('lets the employee withdraw a pending request: closed, balance back, manager told, logged', function () {
    $f = lbcFixture();
    $leave = lbcLeave($f, 'Casual Leave', '2026-11-02', '2026-11-03', 2);
    app(LeaveApprovalService::class)->submit($leave, $f['employeeUser']);

    expect(lbcBalance($f, 'Casual Leave')['pending'])->toBe(2.0);

    // Nobody else withdraws it.
    expect(fn () => app(LeaveCancellationService::class)->withdraw($leave->fresh(), $f['managerUser']))
        ->toThrow(RuntimeException::class);

    $leave = app(LeaveCancellationService::class)->withdraw($leave->fresh(), $f['employeeUser'], 'Plans changed');

    expect($leave->state)->toBe(State::CANCEL)
        ->and($leave->cancelled_by)->toBe($f['employeeUser']->id)
        ->and($leave->cancellation_reason)->toBe('Plans changed')
        ->and($leave->approvalRequest->status)->toBe(LeaveCancellationService::WITHDRAWN_STATUS)
        ->and(lbcBalance($f, 'Casual Leave'))->toMatchArray(['pending' => 0.0, 'remaining' => 5.0])
        ->and(lbcNotices($f['managerUser'], 'leave_withdrawn_'))->toBe(['Ali Employee withdrew a Casual Leave request'])
        ->and($leave->messages()->where('body', 'like', '%withdrew this request%')->exists())->toBeTrue();

    // The engine can no longer move it back, and it cannot be resubmitted or withdrawn again.
    app(LeaveApprovalService::class)->synchronize($leave);
    expect($leave->fresh()->state)->toBe(State::CANCEL)
        ->and(app(LeaveCancellationService::class)->canWithdraw($leave->fresh(), $f['employeeUser']))->toBeFalse()
        ->and(fn () => app(LeaveApprovalService::class)->submit($leave->fresh(), $f['employeeUser']))->toThrow(RuntimeException::class);
});

it('lets the employee ask to cancel a future approved leave and HR cancel it: balance and attendance restored', function () {
    $f = lbcFixture();
    $leave = lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-11-09', '2026-11-11', 3));
    $report = app(MonthlyAttendanceReport::class);

    expect($leave->state)->toBe(State::VALIDATE_TWO)
        ->and(lbcBalance($f, 'Annual Leave')['taken'])->toBe(3.0)
        ->and($report->leaves([$f['employee']->id], '2026-11-01', '2026-11-30'))->toHaveKey($f['employee']->id);

    $cancellation = app(LeaveCancellationService::class);
    $leave = $cancellation->requestCancellation($leave, $f['employeeUser'], 'Trip postponed');

    expect($leave->state)->toBe(State::VALIDATE_TWO)
        ->and($leave->cancellation_request_reason)->toBe('Trip postponed')
        ->and(lbcNotices($f['hrUser'], 'leave_cancellation_requested_'))->toBe(['Ali Employee asks to cancel an approved Annual Leave'])
        ->and(lbcNotices($f['managerUser'], 'leave_cancellation_requested_'))->toHaveCount(1)
        ->and($cancellation->canRequestCancellation($leave, $f['employeeUser']))->toBeFalse();

    $leave = $cancellation->cancel($leave, $f['hrUser'], 'Approved on request');

    expect($leave->state)->toBe(State::CANCEL)
        ->and($leave->cancelled_by)->toBe($f['hrUser']->id)
        ->and($leave->cancellation_reason)->toBe('Approved on request')
        ->and(lbcBalance($f, 'Annual Leave'))->toMatchArray(['taken' => 0.0, 'remaining' => 12.0])
        ->and($report->leaves([$f['employee']->id], '2026-11-01', '2026-11-30'))->toBe([])
        ->and(lbcNotices($f['employeeUser'], 'leave_cancelled_'))->toBe(['Annual Leave of Ali Employee was cancelled'])
        ->and(lbcNotices($f['managerUser'], 'leave_cancelled_'))->toHaveCount(1)
        ->and($leave->messages()->where('body', 'like', '%Approved on request%')->exists())->toBeTrue();

    // The freed days can be booked again on the same dates.
    expect(lbcLeave($f, 'Annual Leave', '2026-11-09', '2026-11-11', 3)->exists)->toBeTrue();
});

it('does not let the employee self-cancel a leave that already started', function () {
    $f = lbcFixture();
    $leave = lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-10-27', '2026-10-29', 3));

    expect(app(LeaveCancellationService::class)->canRequestCancellation($leave, $f['employeeUser']))->toBeFalse()
        ->and(fn () => app(LeaveCancellationService::class)->requestCancellation($leave, $f['employeeUser'], 'Back early'))->toThrow(RuntimeException::class);
});

it('lets only HR of the leave company cancel an approved leave, with a reason', function () {
    $f = lbcFixture();
    $other = lbcFixture('Other Co');
    $leave = lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-10-26', null, 1));
    $cancellation = app(LeaveCancellationService::class);

    // A manager-role user holding hr_approve_leave is not HR and may not cancel it either.
    $managerRole = Role::query()->create(['name' => 'lbc_manager_'.uniqid(), 'guard_name' => 'web']);
    $managerRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'hr_approve_leave', 'guard_name' => 'web']));
    $f['managerUser']->assignRole($managerRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The line manager (who approved it) may not cancel it.
    expect($cancellation->canCancel($leave, $f['managerUser']))->toBeFalse()
        ->and(fn () => $cancellation->cancel($leave, $f['managerUser'], 'Not needed'))
        ->toThrow(RuntimeException::class, 'Only HR of the leave company can cancel an approved leave.')
        ->and($leave->fresh()->state)->toBe(State::VALIDATE_TWO)
        ->and($cancellation->canCancel($leave, $f['employeeUser']))->toBeFalse()
        ->and($cancellation->canCancel($leave, $other['hrUser']))->toBeFalse()
        ->and(fn () => $cancellation->cancel($leave, $other['hrUser'], 'No'))->toThrow(RuntimeException::class)
        ->and(fn () => $cancellation->cancel($leave, $f['hrUser'], '   '))->toThrow(RuntimeException::class);

    // HR may cancel even a past leave (correction), without an employee request.
    $leave = $cancellation->cancel($leave, $f['hrUser'], 'Was at work');
    expect($leave->state)->toBe(State::CANCEL)
        ->and(lbcBalance($f, 'Annual Leave')['taken'])->toBe(0.0);
});

it('lets HR decline a cancellation request: the leave stays approved and the employee is told', function () {
    $f = lbcFixture();
    $leave = lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-12-01', null, 1));
    $cancellation = app(LeaveCancellationService::class);
    $leave = $cancellation->requestCancellation($leave, $f['employeeUser'], 'Maybe not');

    $leave = $cancellation->declineCancellation($leave, $f['hrUser'], 'Year-end cover agreed');

    expect($leave->state)->toBe(State::VALIDATE_TWO)
        ->and($leave->cancellation_requested_at)->toBeNull()
        ->and(lbcNotices($f['employeeUser'], 'leave_cancellation_declined_'))->toBe(['Your Annual Leave stays approved'])
        ->and(lbcBalance($f, 'Annual Leave')['taken'])->toBe(1.0);
});

it('creates next year allocations with carry-forward up to the cap, idempotently', function () {
    $f = lbcFixture();
    LeaveAllocationPolicy::forCompany((int) $f['company']->id);

    // 2026: Annual 12 with 3 taken -> 9 unused, cap 6. Casual 5 with 2 taken, cap 0. Sick 8, cap 0.
    lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-11-09', '2026-11-11', 3));
    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-12', '2026-11-13', 2));

    $service = app(YearlyLeaveAllocationService::class);

    $dry = $service->run(2027, (int) $f['company']->id, true);
    expect($dry['created'])->toBe(6) // manager + employee, 3 types each (the HR user has no employee record)
        ->and(LeaveAllocation::query()->whereIn('employee_id', [$f['employee']->id])->whereYear('date_from', 2027)->count())->toBe(0);

    $result = $service->run(2027, (int) $f['company']->id);
    $allocations = LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->with('holidayStatus')->get()->keyBy('holidayStatus.name');

    expect($result['created'])->toBe($dry['created'])
        ->and((float) $allocations['Annual Leave']->number_of_days)->toBe(18.0)
        ->and((float) $allocations['Annual Leave']->expiring_carryover_days)->toBe(6.0)
        ->and($allocations['Annual Leave']->notes)->toContain('6 carried forward from 2026 (cap 6)')
        ->and($allocations['Annual Leave']->state)->toBe(State::VALIDATE_TWO->value)
        ->and($allocations['Annual Leave']->date_to->toDateString())->toBe('2027-12-31')
        // Carried days do not expire before the year end.
        ->and($allocations['Annual Leave']->carried_over_days_expiration_date)->toBeNull()
        ->and((float) $allocations['Casual Leave']->number_of_days)->toBe(5.0)
        ->and((float) $allocations['Casual Leave']->expiring_carryover_days)->toBe(0.0)
        ->and((float) $allocations['Sick Leave']->number_of_days)->toBe(8.0)
        ->and((float) $allocations['Sick Leave']->expiring_carryover_days)->toBe(0.0);

    $again = $service->run(2027, (int) $f['company']->id);
    expect($again['created'])->toBe(0)
        ->and($again['existing'])->toBe($result['created'])
        ->and(LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->count())->toBe(3);
});

it('carries forward less than the cap when fewer days are unused, and never a negative balance', function () {
    $f = lbcFixture();
    LeaveAllocationPolicy::forCompany((int) $f['company']->id);
    LeaveAllocationPolicy::query()->where('company_id', $f['company']->id)->update(['carry_forward_cap' => 6]);

    lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-11-02', '2026-11-11', 10)); // 2 unused
    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-12', '2026-11-16', 3)); // 2 unused
    lbcApprove($f, lbcLeave($f, 'Half day', '2026-11-20', null, 0.5, ['request_unit_half' => true])); // counts nowhere

    app(YearlyLeaveAllocationService::class)->run(2027, (int) $f['company']->id);
    $days = LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->with('holidayStatus')->get()
        ->mapWithKeys(fn (LeaveAllocation $allocation): array => [$allocation->holidayStatus->name => (float) $allocation->number_of_days]);

    expect($days['Annual Leave'])->toBe(14.0)
        ->and($days['Casual Leave'])->toBe(7.0)
        ->and($days['Sick Leave'])->toBe(14.0);
});

it('prorates a mid-year joiner by the months left and skips leavers and future joiners', function () {
    $f = lbcFixture();
    LeaveAllocationPolicy::forCompany((int) $f['company']->id);

    $joiner = Employee::query()->create(['company_id' => $f['company']->id, 'name' => 'April Joiner', 'is_active' => true, 'joining_date' => '2027-04-15']);
    $future = Employee::query()->create(['company_id' => $f['company']->id, 'name' => 'Next Year Joiner', 'is_active' => true, 'joining_date' => '2028-01-10']);
    $leaver = Employee::query()->create(['company_id' => $f['company']->id, 'name' => 'Left', 'is_active' => true, 'departure_date' => '2026-11-30']);
    $inactive = Employee::query()->create(['company_id' => $f['company']->id, 'name' => 'Inactive', 'is_active' => false]);

    app(YearlyLeaveAllocationService::class)->run(2027, (int) $f['company']->id);

    $joinerDays = LeaveAllocation::query()->where('employee_id', $joiner->id)->whereYear('date_from', 2027)->with('holidayStatus')->get()
        ->mapWithKeys(fn (LeaveAllocation $allocation): array => [$allocation->holidayStatus->name => (float) $allocation->number_of_days]);

    // Joined in April: 9 months left. Annual 12 x 9/12 = 9, Sick 8 x 9/12 = 6, Casual 5 x 9/12 = 3.75 -> 4.
    expect($joinerDays->all())->toEqual(['Annual Leave' => 9.0, 'Sick Leave' => 6.0, 'Casual Leave' => 4.0])
        ->and(YearlyLeaveAllocationService::prorate(12, Carbon::parse('2027-12-01'), 2027))->toBe(1.0)
        ->and(LeaveAllocation::query()->whereIn('employee_id', [$future->id, $leaver->id, $inactive->id])->whereYear('date_from', 2027)->count())->toBe(0);
});

it('keeps companies apart: per-company run, policies and leave types', function () {
    $a = lbcFixture('Company A');
    $b = lbcFixture('Company B');
    LeaveAllocationPolicy::forCompany((int) $a['company']->id);
    LeaveAllocationPolicy::forCompany((int) $b['company']->id);
    LeaveAllocationPolicy::query()->where('company_id', $b['company']->id)->update(['annual_days' => 20]);

    app(YearlyLeaveAllocationService::class)->run(2027, (int) $a['company']->id);

    expect(LeaveAllocation::query()->where('employee_id', $a['employee']->id)->whereYear('date_from', 2027)->count())->toBe(3)
        ->and(LeaveAllocation::query()->where('employee_id', $b['employee']->id)->whereYear('date_from', 2027)->count())->toBe(0);

    app(YearlyLeaveAllocationService::class)->run(2027, (int) $b['company']->id);
    $bAnnual = LeaveAllocation::query()->where('employee_id', $b['employee']->id)->where('holiday_status_id', lbcType($b, 'Annual Leave')->id)->whereYear('date_from', 2027)->value('number_of_days');
    expect((float) $bAnnual)->toBe(26.0); // 20 + 6 carried (12 unused in 2026)

    // Company A's leave type cannot be used for a Company B employee.
    $refusal = app(LeaveBalanceService::class)->refusal($b['employee'], lbcType($a, 'Annual Leave'), 1, '2026-11-02');
    expect($refusal['reason'])->toBe('company');
});

it('runs from the command, dry run included', function () {
    $f = lbcFixture();

    $this->artisan('time-off:yearly-allocation', ['--year' => 2027, '--company' => $f['company']->id, '--dry-run' => true])
        ->expectsOutputToContain('[dry run] Yearly leave allocation 2027')
        ->assertSuccessful();

    expect(LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->count())->toBe(0)
        // The policies were provisioned with the company (Company::created); the dry run adds none.
        ->and(LeaveAllocationPolicy::query()->where('company_id', $f['company']->id)->count())->toBe(3);

    $this->artisan('time-off:yearly-allocation', ['--year' => 2027, '--company' => $f['company']->id])->assertSuccessful();

    expect(LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->count())->toBe(3)
        ->and(LeaveAllocationPolicy::query()->where('company_id', $f['company']->id)->count())->toBe(3);
});

it('lists who is on approved leave per day, by department, without cancelled leave', function () {
    $f = lbcFixture();
    $approved = lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-11-09', '2026-11-10', 2));
    $halfDay = lbcApprove($f, lbcLeave($f, 'Half day', '2026-11-12', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'afternoon']));
    $cancelled = lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-13', null, 1));
    app(LeaveCancellationService::class)->cancel($cancelled, $f['hrUser'], 'Not needed');
    lbcLeave($f, 'Sick Leave', '2026-11-16', null, 1); // pending

    $visible = [$f['employee']->id];
    $calendar = app(LeaveCalendarService::class)->month((int) $f['company']->id, '2026-11', $visible);
    $byDate = collect($calendar['days'])->keyBy('date');

    expect($calendar['label'])->toBe('November 2026')
        ->and($calendar['people'])->toBe(1)
        ->and($byDate['2026-11-09']['entries'][0])->toMatchArray(['employee' => 'Ali Employee', 'type' => 'Annual Leave', 'portion' => 'full day', 'approved' => true])
        ->and($byDate['2026-11-10']['entries'])->toHaveCount(1)
        ->and($byDate['2026-11-12']['entries'][0]['portion'])->toBe('second half')
        ->and($byDate['2026-11-13']['entries'])->toBe([])
        ->and($byDate['2026-11-16']['entries'])->toBe([]);

    $withPending = collect(app(LeaveCalendarService::class)->month((int) $f['company']->id, '2026-11', $visible, null, true)['days'])->keyBy('date');
    expect($withPending['2026-11-16']['entries'][0]['approved'])->toBeFalse();

    $otherDepartment = Department::query()->create(['name' => 'Other '.uniqid(), 'company_id' => $f['company']->id]);
    $filtered = app(LeaveCalendarService::class)->month((int) $f['company']->id, '2026-11', $visible, (int) $otherDepartment->id);
    expect($filtered['people'])->toBe(0)
        // Another company's calendar never shows these leaves.
        ->and(app(LeaveCalendarService::class)->month((int) lbcFixture('Elsewhere')['company']->id, '2026-11', $visible)['people'])->toBe(0);
});

it("renders the Who's on leave page for HR only", function () {
    $f = lbcFixture();
    lbcApprove($f, lbcLeave($f, 'Annual Leave', '2026-10-29', null, 1));
    $hr = lbcUser($f['company'], 'Page HR', ['view_any_time_off_time::off', 'hr_view_all_records']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Auth::login($f['employeeUser']);
    expect(LeaveCalendar::canAccess())->toBeFalse();

    Auth::login($hr);
    expect(LeaveCalendar::canAccess())->toBeTrue();

    Livewire::test(LeaveCalendar::class)
        ->assertSee('October 2026')
        ->assertSee('Ali Employee')
        ->set('departmentId', (int) Department::query()->create(['name' => 'Empty '.uniqid(), 'company_id' => $f['company']->id])->id)
        ->assertDontSee('Ali Employee');
});

it('shows the live balance on the My Time Off request form', function () {
    $f = lbcFixture();
    $role = Role::query()->firstOrCreate(['name' => 'lbc_self_service', 'guard_name' => 'web']);
    foreach (['view_any_time_off_my::time::off', 'create_time_off_my::time::off', 'view_time_off_my::time::off'] as $permission) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $f['employeeUser']->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::login($f['employeeUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(CreateMyTimeOff::class)
        ->fillForm(['holiday_status_id' => lbcType($f, 'Casual Leave')->id, 'request_date_from' => '2026-11-04'])
        ->assertSee('Casual Leave 2026: available 5, taken 0, pending 0, remaining 5 day(s).')
        ->assertSee('Half a day: 0.5 day of Casual Leave.')
        ->fillForm(['holiday_status_id' => lbcType($f, 'Half day')->id, 'request_date_from' => '2026-11-04'])
        ->assertSee('Half day does not count as leave: no balance is deducted.')
        ->assertSee('Half day does not count as leave: nothing is deducted from your leave balance.')
        ->assertDontSee('Uses your Casual Leave balance');
});

/** @return array<string, float> 2026 (or $year) allocation days of an employee by leave type */
function lbcAllocatedDays(Employee $employee, int $year = 2026): array
{
    return LeaveAllocation::query()->where('employee_id', $employee->id)->whereYear('date_from', $year)->with('holidayStatus')->get()
        ->mapWithKeys(fn (LeaveAllocation $allocation): array => [$allocation->holidayStatus->name => (float) $allocation->number_of_days])
        ->all();
}

it('prorates the hire-time allocation from the joining date, to the nearest half day', function () {
    $f = lbcFixture();
    $hire = fn (string $name, ?string $joined): Employee => Employee::query()->create(['company_id' => $f['company']->id, 'name' => $name, 'is_active' => true, 'joining_date' => $joined]);

    $july = $hire('July Joiner', '2026-07-15');
    $october = $hire('October Joiner', '2026-10-01');
    $veteran = $hire('Veteran', '2024-03-01');
    $nextYear = $hire('February Joiner', '2027-02-01');

    // July: 6 months left (July counted). October: 3 months, Casual 1.25 -> 1.5.
    expect(lbcAllocatedDays($july))->toEqual(['Annual Leave' => 6.0, 'Sick Leave' => 4.0, 'Casual Leave' => 2.5])
        ->and(lbcAllocatedDays($october))->toEqual(['Annual Leave' => 3.0, 'Sick Leave' => 2.0, 'Casual Leave' => 1.5])
        ->and(lbcAllocatedDays($veteran))->toEqual(['Annual Leave' => 12.0, 'Sick Leave' => 8.0, 'Casual Leave' => 5.0])
        ->and(lbcAllocatedDays($nextYear))->toBe([])
        ->and(LeaveAllocation::query()->where('employee_id', $july->id)->where('holiday_status_id', lbcType($f, 'Casual Leave')->id)->value('notes'))
        ->toBe('Hire-time allocation 2026: 2.5 of 5 day(s), prorated from the joining date 2026-07-15.');

    // The yearly allocation of 2026 sees them allocated; next year's prorates the February joiner (11 months).
    app(YearlyLeaveAllocationService::class)->run(2026, (int) $f['company']->id);
    app(YearlyLeaveAllocationService::class)->run(2027, (int) $f['company']->id);

    expect(lbcAllocatedDays($july))->toEqual(['Annual Leave' => 6.0, 'Sick Leave' => 4.0, 'Casual Leave' => 2.5])
        ->and(lbcAllocatedDays($nextYear, 2027))->toEqual(['Annual Leave' => 11.0, 'Sick Leave' => 7.5, 'Casual Leave' => 4.5]);
});

it('provisions every new company its own Annual, Sick and Casual Leave like the first company, with 12 / 8 / 5 policies, idempotently', function () {
    $template = LeaveType::query()->whereNotNull('company_id')->where('name', 'Sick Leave')->orderBy('company_id')->orderBy('id')->firstOrFail();
    $company = Company::factory()->create(['name' => 'Provisioned '.uniqid(), 'is_active' => true]);

    $types = LeaveType::query()->where('company_id', $company->id)->whereIn('name', ['Annual Leave', 'Sick Leave', 'Casual Leave'])->get()->keyBy('name');
    $policies = fn (): array => LeaveAllocationPolicy::query()->where('company_id', $company->id)->with('leaveType')->get()
        ->mapWithKeys(fn (LeaveAllocationPolicy $policy): array => [$policy->leaveType->name => [(float) $policy->annual_days, (float) $policy->carry_forward_cap]])
        ->all();

    expect($types)->toHaveCount(3)
        ->and($types['Sick Leave']->color)->toBe($template->color)
        ->and($types['Sick Leave']->requires_allocation)->toBe('yes')
        ->and($types['Sick Leave']->getRawOriginal('leave_validation_type'))->toBe($template->getRawOriginal('leave_validation_type'))
        ->and($types['Sick Leave']->request_unit)->toBe($template->request_unit)
        ->and($policies())->toEqual(['Annual Leave' => [12.0, 6.0], 'Sick Leave' => [8.0, 0.0], 'Casual Leave' => [5.0, 0.0]]);

    // ...and its leave_request workflow: line manager, then HR.
    $steps = fn () => ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'leave_request')->firstOrFail()
        ->steps()->orderBy('sequence')->pluck('name')->all();
    expect($steps())->toBe(['Line Manager Review', 'HR Review']);
    app(LeaveWorkflowSeeder::class)->provisionWorkflow($company);
    expect(ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'leave_request')->count())->toBe(1)
        ->and($steps())->toBe(['Line Manager Review', 'HR Review']);

    // Re-running changes nothing, adds nothing, and a duplicate type gets no second policy.
    $types['Casual Leave']->update(['color' => '#000000']);
    LeaveType::query()->create(['company_id' => $company->id, 'name' => 'Annual Leave', 'requires_allocation' => 'yes', 'is_active' => true]);
    app(LeaveWorkflowSeeder::class)->provisionLeaveTypes($company);
    app(LeaveWorkflowSeeder::class)->provisionLeaveTypes($company);

    expect(LeaveType::query()->where('company_id', $company->id)->whereIn('name', ['Sick Leave', 'Casual Leave'])->count())->toBe(2)
        ->and($types['Casual Leave']->fresh()->color)->toBe('#000000')
        ->and(LeaveAllocationPolicy::query()->where('company_id', $company->id)->count())->toBe(3)
        ->and($policies())->toEqual(['Annual Leave' => [12.0, 6.0], 'Sick Leave' => [8.0, 0.0], 'Casual Leave' => [5.0, 0.0]]);
});

it('tells the company HR, not managers holding hr_approve_leave, about a leave without a line manager', function () {
    $f = lbcFixture();
    $other = lbcFixture('Other HR Co');

    $role = function (array $permissions): Role {
        $role = Role::query()->create(['name' => 'lbc_role_'.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $role;
    };
    $hrRole = $role(['hr_approve_leave', 'hr_view_all_records']);
    $managerRole = $role(['hr_approve_leave']);

    $hr = lbcUser($f['company'], 'Company HR');
    $hr->assignRole($hrRole);
    $plainManager = lbcUser($f['company'], 'Plain Manager');
    $plainManager->assignRole($managerRole);
    $otherHr = lbcUser($other['company'], 'Other HR');
    $otherHr->assignRole($hrRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $loneUser = lbcUser($f['company'], 'Lone Employee');
    $lone = Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $loneUser->id, 'name' => 'Lone Employee', 'is_active' => true]);

    app(LeaveApprovalService::class)->submit(lbcLeave($f, 'Annual Leave', '2026-11-09', null, 1, [], $lone), $loneUser);

    $title = 'Lone Employee requested time off: Annual Leave';
    $notices = fn (User $user) => $user->notifications()->get()->filter(fn ($notification): bool => ($notification->data['title'] ?? null) === $title);

    expect($notices($hr))->toHaveCount(1)
        ->and((string) $notices($hr)->first()->data['body'])->toContain('[Forwarded to HR: no line manager assigned]')
        ->and($notices($plainManager))->toHaveCount(0)
        ->and($notices($f['hrUser']))->toHaveCount(1) // the fixture's HR (company-wide view + leave approval) is real HR
        ->and($notices($otherHr))->toHaveCount(0)
        ->and($notices($loneUser))->toHaveCount(0);
});

it('moves 2026 hire-time and yearly allocations to Casual 5 and Sick 8, never below the days used (migration 000023)', function () {
    $f = lbcFixture();
    $casual = lbcType($f, 'Casual Leave');
    $sick = lbcType($f, 'Sick Leave');
    $hire = function (string $name) use ($f): Employee {
        $user = lbcUser($f['company'], $name);

        return Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $user->id, 'name' => $name, 'is_active' => true]);
    };
    $light = $f['employee'];
    $heavy = $hire('Heavy User');
    $full = $hire('Full User');
    $manual = $hire('Manual Grant');

    // The allocations as the listener / yearly run made them before the decision: Casual 8, Sick 5.
    $old = fn (Employee $employee, LeaveType $type, float $days, ?string $notes = null) => LeaveAllocation::query()
        ->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->update(['number_of_days' => $days, 'notes' => $notes]);
    foreach ([$light, $heavy, $full] as $employee) {
        $old($employee, $casual, 8);
        $old($employee, $sick, 5);
    }
    $old($heavy, $casual, 8, 'Yearly allocation 2026: 8 day(s) granted.');
    $old($manual, $casual, 8, 'Granted by HR');
    LeaveAllocationPolicy::query()->where('leave_type_id', $casual->id)->update(['annual_days' => 8]);
    LeaveAllocationPolicy::query()->where('leave_type_id', $sick->id)->update(['annual_days' => 5]);

    $approved = ['state' => State::VALIDATE_TWO];
    lbcLeave($f, 'Casual Leave', '2026-03-02', '2026-03-03', 2, $approved, $light);
    lbcLeave($f, 'Casual Leave', '2026-03-02', '2026-03-09', 6, $approved, $heavy);
    lbcLeave($f, 'Casual Leave', '2026-11-16', null, 1, [], $heavy); // pending: used 7
    lbcLeave($f, 'Casual Leave', '2026-03-02', '2026-03-11', 8, $approved, $full);

    $migration = require base_path('plugins/webkul/time-off/database/migrations/2026_10_29_000023_apply_twenty_five_day_leave_policy.php');
    $migration->up();
    $migration->up(); // idempotent

    $allocation = fn (Employee $employee, LeaveType $type): LeaveAllocation => LeaveAllocation::query()->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->firstOrFail();

    expect((float) $allocation($light, $casual)->number_of_days)->toBe(5.0)
        ->and($allocation($light, $casual)->notes)->toBe('Leave policy of 2026-10-29 (25 days: Annual 12, Sick 8, Casual 5): Casual Leave 8 -> 5 day(s).')
        ->and((float) $allocation($heavy, $casual)->number_of_days)->toBe(7.0)
        ->and($allocation($heavy, $casual)->notes)->toContain("Yearly allocation 2026: 8 day(s) granted.\nLeave policy of 2026-10-29")
        ->and($allocation($heavy, $casual)->notes)->toContain('Casual Leave 8 -> 7 day(s) (not below the 7 day(s) already used).')
        ->and((float) $allocation($full, $casual)->number_of_days)->toBe(8.0)
        ->and($allocation($full, $casual)->notes)->toBeNull()
        ->and((float) $allocation($manual, $casual)->number_of_days)->toBe(8.0)
        ->and($allocation($manual, $casual)->notes)->toBe('Granted by HR')
        ->and((float) $allocation($light, $sick)->number_of_days)->toBe(8.0)
        ->and((float) $allocation($full, $sick)->number_of_days)->toBe(8.0)
        ->and((float) $allocation($manual, $sick)->number_of_days)->toBe(8.0)
        ->and($allocation($manual, $sick)->notes)->toBeNull()
        ->and((float) LeaveAllocationPolicy::query()->where('leave_type_id', $casual->id)->value('annual_days'))->toBe(5.0)
        ->and((float) LeaveAllocationPolicy::query()->where('leave_type_id', $sick->id)->value('annual_days'))->toBe(8.0)
        ->and(lbcBalance($f, 'Casual Leave'))->toMatchArray(['allocated' => 5.0, 'taken' => 2.0, 'remaining' => 3.0]);
});
