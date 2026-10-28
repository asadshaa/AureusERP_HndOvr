<?php

/**
 * Leave balance on the request (LeaveBalanceService, enforced on the Leave
 * model), Half day drawing 0.5 from Casual Leave, withdraw / cancellation
 * (LeaveCancellationService), the yearly allocation with carry-forward
 * (YearlyLeaveAllocationService, time-off:yearly-allocation) and the
 * "Who's on leave" month list (LeaveCalendarService).
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
    // Company::created provisions Half day / Short leave / On Duty.
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

    $department = Department::query()->create(['name' => 'Ops '.uniqid(), 'company_id' => $company->id]);

    $managerUser = lbcUser($company, 'Sana Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Sana Manager', 'is_active' => true]);

    $employeeUser = lbcUser($company, 'Ali Employee');
    // Created now (2026): the hire-time listener allocates Annual 12, Casual 8, Sick 5 for 2026.
    $employee = Employee::query()->create([
        'company_id'    => $company->id,
        'user_id'       => $employeeUser->id,
        'parent_id'     => $manager->id,
        'department_id' => $department->id,
        'name'          => 'Ali Employee',
        'is_active'     => true,
    ]);

    $hrUser = lbcUser($company, 'Hira HR', ['hr_approve_leave']);

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
        ->toBe('Casual Leave 2026: available 8, taken 0, pending 0, remaining 8 day(s).');

    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-02', '2026-11-03', 2));
    lbcLeave($f, 'Casual Leave', '2026-11-10', null, 1);

    expect(lbcBalance($f, 'Casual Leave'))->toMatchArray(['allocated' => 8.0, 'taken' => 2.0, 'pending' => 1.0, 'remaining' => 5.0])
        ->and($service->summary($f['employee'], lbcType($f, 'Casual Leave'), 2026))
        ->toBe('Casual Leave 2026: available 8, taken 2, pending 1, remaining 5 day(s).')
        // Next year has no allocation yet: nothing taken there either.
        ->and(lbcBalance($f, 'Casual Leave', 2027))->toMatchArray(['allocated' => 0.0, 'taken' => 0.0, 'pending' => 0.0]);
});

it('refuses on save a request above the remaining balance, whatever path writes it', function () {
    $f = lbcFixture();

    lbcLeave($f, 'Casual Leave', '2026-11-02', '2026-11-06', 5);

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

    expect(lbcLeave($f, 'Sick Leave', '2026-11-02', '2026-11-06', 5)->exists)->toBeTrue()
        ->and(lbcLeave($f, 'Sick Leave', '2026-11-09', '2026-11-10', 2)->exists)->toBeTrue();

    expect(fn () => lbcLeave($f, 'Sick Leave', '2026-11-11', null, 0.5, ['request_unit_half' => true]))
        ->toThrow(ValidationException::class, 'Not enough Sick Leave balance');
});

it('refuses a type that requires an allocation when there is none', function () {
    $f = lbcFixture();
    LeaveAllocation::query()->where('employee_id', $f['employee']->id)->delete();

    expect(fn () => lbcLeave($f, 'Annual Leave', '2026-11-02'))
        ->toThrow(ValidationException::class, 'There is no approved Annual Leave allocation for 2026');
});

it('counts a Half day as 0.5 day of Casual Leave, needing no allocation of its own', function () {
    $f = lbcFixture();
    $halfDay = lbcType($f, 'Half day');

    expect($halfDay->requires_allocation)->toBe('no')
        ->and(app(LeaveBalanceService::class)->summary($f['employee'], $halfDay, 2026))
        ->toBe('Uses your Casual Leave balance. Casual Leave 2026: available 8, taken 0, pending 0, remaining 8 day(s).');

    lbcApprove($f, lbcLeave($f, 'Half day', '2026-11-04', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'morning']));
    lbcLeave($f, 'Half day', '2026-11-05', null, 0.5, ['request_unit_half' => true, 'request_date_from_period' => 'afternoon']);

    expect(lbcBalance($f, 'Casual Leave'))->toMatchArray(['taken' => 0.5, 'pending' => 0.5, 'remaining' => 7.0])
        ->and(lbcBalance($f, 'Half day'))->toMatchArray(['pool_type' => 'Casual Leave', 'shared_pool' => true, 'remaining' => 7.0])
        // Annual Leave is untouched.
        ->and(lbcBalance($f, 'Annual Leave')['remaining'])->toBe(12.0);

    // With Casual Leave used up, a Half day is refused.
    lbcLeave($f, 'Casual Leave', '2026-11-09', '2026-11-17', 7);
    expect(fn () => lbcLeave($f, 'Half day', '2026-11-18', null, 0.5, ['request_unit_half' => true]))
        ->toThrow(ValidationException::class, 'Half day uses the Casual Leave balance');
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
        ->and(lbcBalance($f, 'Casual Leave'))->toMatchArray(['pending' => 0.0, 'remaining' => 8.0])
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

    expect($cancellation->canCancel($leave, $f['managerUser']))->toBeFalse()
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

    // 2026: Annual 12 with 3 taken -> 9 unused, cap 6. Casual 8 with 2 taken, cap 0.
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
        ->and((float) $allocations['Casual Leave']->number_of_days)->toBe(8.0)
        ->and((float) $allocations['Sick Leave']->number_of_days)->toBe(5.0);

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
    lbcApprove($f, lbcLeave($f, 'Casual Leave', '2026-11-12', '2026-11-19', 6));
    lbcApprove($f, lbcLeave($f, 'Half day', '2026-11-20', null, 0.5, ['request_unit_half' => true])); // Casual: 1.5 unused

    app(YearlyLeaveAllocationService::class)->run(2027, (int) $f['company']->id);
    $days = LeaveAllocation::query()->where('employee_id', $f['employee']->id)->whereYear('date_from', 2027)->with('holidayStatus')->get()
        ->mapWithKeys(fn (LeaveAllocation $allocation): array => [$allocation->holidayStatus->name => (float) $allocation->number_of_days]);

    expect($days['Annual Leave'])->toBe(14.0)
        ->and($days['Casual Leave'])->toBe(9.5)
        ->and($days['Sick Leave'])->toBe(10.0);
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

    expect($joinerDays->all())->toEqual(['Annual Leave' => 9.0, 'Casual Leave' => 6.0, 'Sick Leave' => 4.0])
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
        // A dry run does not save the default policies either.
        ->and(LeaveAllocationPolicy::query()->where('company_id', $f['company']->id)->count())->toBe(0);

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
        ->fillForm(['holiday_status_id' => lbcType($f, 'Half day')->id, 'request_date_from' => '2026-11-04'])
        ->assertSee('Uses your Casual Leave balance. Casual Leave 2026: available 8, taken 0, pending 0, remaining 8 day(s).');
});
