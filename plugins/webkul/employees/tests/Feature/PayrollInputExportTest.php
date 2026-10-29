<?php

/**
 * HR parity plan 1.5 / 3.3: loan instalments and advance deductions as
 * first-class rows (created on final approval, backfilled, marked deducted /
 * skipped / undone, rescheduled, settled, audited) and the monthly
 * payroll-input export (finalised snapshot vs live DRAFT, sensitive columns,
 * mark-as-deducted, company isolation).
 */

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\PayrollInput;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\RequestInstalmentsRelationManager;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\RequestInstalment;
use Webkul\Employee\Models\RequestInstalmentEvent;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\OvertimeService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\Payroll\PayrollInputExcel;
use Webkul\Employee\Services\Payroll\PayrollInputReport;
use Webkul\Employee\Services\Payroll\RequestInstalmentService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Partner\Models\BankAccount;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    // October 2026 is over (2026-11-05 11:00 in Karachi); November is the current month.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'               => '10:00',
        'attendance.monthly_report.office_end'                 => '18:00',
        'attendance.monthly_report.daily_required_minutes'     => 480,
        'attendance.monthly_report.max_outside_credit_minutes' => 60,
        'attendance.monthly_report.late_grace_minutes'         => 15,
        'attendance.monthly_report.timezone'                   => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'               => [6, 7],
        'attendance.monthly_report.show_off_days'              => false,
        'attendance.monthly_report.count_off_day_work'         => false,
        'attendance.time_bank.monthly_grace_minutes'           => 0,
        'attendance.time_bank.carry_over'                      => false,
        'attendance.notifications.late_check_in'               => false,
    ]);
    $this->instalments = app(RequestInstalmentService::class);
    $this->payroll = app(PayrollInputReport::class);
});

function pinCompany(string $name): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true, 'currency_id' => Currency::query()->where('code', 'PKR')->value('id')]);
}

function pinUser(Company $company, array $permissions = [], ?string $role = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null) {
        $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->assignRole($roleModel);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** HR of the company who runs payroll input: sees everyone, approves (engine "hr" route), finalises months. */
function pinPayrollHr(Company $company, bool $sensitive = true): User
{
    return pinUser($company, array_values(array_filter([
        HrPermissions::ViewAllRecords,
        HrPermissions::ViewAttendance,
        HrPermissions::ManageAttendance,
        HrPermissions::ManageEmployeeRequests,
        HrPermissions::ApproveLeave,
        HrPermissions::FinaliseAttendance,
        HrPermissions::ExportPayroll,
        $sensitive ? HrPermissions::ViewSensitiveEmployeeData : null,
    ])), $sensitive ? 'pin_payroll_hr' : 'pin_payroll_hr_no_sensitive');
}

function pinEmployee(Company $company, string $name, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

/** An employee with a user, reporting to a line manager with a user, and the company's payroll HR. */
function pinTeam(Company $company): array
{
    $managerUser = pinUser($company);
    $manager = pinEmployee($company, 'Line Manager', ['user_id' => $managerUser->id]);
    $employeeUser = pinUser($company);
    $employee = pinEmployee($company, 'Bilal Ahmed', [
        'user_id'         => $employeeUser->id,
        'parent_id'       => $manager->id,
        'employee_number' => 'PIN-'.$company->id.'-01',
        'cnic'            => sprintf('35202-%07d-1', random_int(0, 9999999)),
        'base_salary'     => '100000',
        'job_title'       => 'Dispatcher',
        'joining_date'    => '2024-03-01',
    ]);

    return ['company' => $company, 'employee' => $employee, 'employee_user' => $employeeUser, 'manager' => $manager, 'manager_user' => $managerUser, 'hr' => pinPayrollHr($company)];
}

/** Punches in Karachi local time ("H:i"). */
function pinPunch(Employee $employee, string $date, string $in, ?string $out): AttendanceRecord
{
    $utc = fn (?string $time): ?string => $time === null ? null : CarbonImmutable::parse($date.' '.$time, 'Asia/Karachi')->setTimezone('UTC')->toDateTimeString();

    return AttendanceRecord::query()->create([
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => $date,
        'check_in'        => $utc($in),
        'check_out'       => $utc($out),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
}

/** Filed by the employee, approved by the line manager and then HR (the final approval). */
function pinApproved(array $team, string $code, string $amount, array $payload): EmployeeRequest
{
    $service = app(EmployeeRequestService::class);
    $request = EmployeeRequest::query()->create([
        'company_id'      => $team['company']->id,
        'employee_id'     => $team['employee']->id,
        'request_type_id' => EmployeeRequestType::query()->where('company_id', $team['company']->id)->where('code', $code)->value('id'),
        'requested_by'    => $team['employee_user']->id,
        'amount'          => $amount,
        'payload'         => $payload,
    ]);

    $service->submit($request, $team['employee_user']);
    $service->approve($request->fresh(), $team['manager_user']);

    return $service->approve($request->fresh(), $team['hr']);
}

/** A loan of 30,000 in 3 instalments and an advance of 20,000, both from October 2026 (filed and approved in October). */
function pinLoanAndAdvance(array $team): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-05 06:00:00', 'UTC'));
    $loan = pinApproved($team, EmployeeRequestKinds::LoanCode, '30000', ['purpose' => 'Motorbike', 'instalments' => 3, 'start_month' => '2026-10']);
    $advance = pinApproved($team, EmployeeRequestKinds::SalaryAdvanceCode, '20000', ['reason' => 'Rent', 'deduct_month' => '2026-10']);
    test()->travelTo(CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC'));

    return [$loan, $advance];
}

function pinLeaveType(Company $company, string $name, bool $unpaid): LeaveType
{
    return LeaveType::query()->firstOrCreate(
        ['company_id' => $company->id, 'name' => $name],
        ['is_active' => true, 'requires_allocation' => 'no', 'request_unit' => 'day', 'time_type' => 'leave', 'unpaid' => $unpaid],
    );
}

function pinLeave(Employee $employee, LeaveType $type, string $date, array $attributes = []): Leave
{
    return Leave::query()->create($attributes + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $type->id,
        'state'             => 'validate_two',
        'request_date_from' => $date,
        'request_date_to'   => $date,
        'number_of_days'    => 1,
    ]);
}

/**
 * October 2026 (22 working days, weekends 3/4, 10/11, 17/18, 24/25, 31) for
 * Bilal: late 20 min on the 1st, absent on the 5th, paid leave on the 6th,
 * unpaid leave on the 7th, an unpaid morning half day on the 8th, 2h
 * approved weekday overtime on the 9th, 4h approved overtime on Saturday the
 * 10th, office hours on every other working day.
 */
function pinOctober(array $team): void
{
    $employee = $team['employee'];
    $paid = pinLeaveType($team['company'], 'Annual Leave (payroll test)', false);
    $unpaid = pinLeaveType($team['company'], 'Unpaid Leave (payroll test)', true);

    pinPunch($employee, '2026-10-01', '10:20', '18:00');
    pinPunch($employee, '2026-10-02', '10:00', '18:00');
    pinLeave($employee, $paid, '2026-10-06');
    pinLeave($employee, $unpaid, '2026-10-07');
    pinLeave($employee, $unpaid, '2026-10-08', ['request_unit_half' => true, 'request_date_from_period' => 'morning', 'number_of_days' => 0.5]);
    pinPunch($employee, '2026-10-08', '14:00', '18:00');
    pinPunch($employee, '2026-10-09', '10:00', '20:00');
    pinPunch($employee, '2026-10-10', '10:00', '14:00');

    foreach ([12, 13, 14, 15, 16, 19, 20, 21, 22, 23, 26, 27, 28, 29, 30] as $day) {
        pinPunch($employee, sprintf('2026-10-%02d', $day), '10:00', '18:00');
    }

    $overtime = app(OvertimeService::class);
    foreach (['2026-10-09' => 120, '2026-10-10' => 240] as $date => $minutes) {
        $request = $overtime->request($employee, $team['employee_user'], $date, $minutes, 'Vessel loading');
        $overtime->approve($request->fresh(), $team['manager_user']);
        $overtime->approve($request->fresh(), $team['hr'], $minutes);
    }
}

function pinRow(array $report, string $name): array
{
    return collect($report['rows'])->firstWhere('name', $name) ?? throw new RuntimeException("No payroll row for {$name}.");
}

function pinWorkbook(array $report): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'pin').'.xlsx';
    (new Xlsx(app(PayrollInputExcel::class)->build($report)))->save($path);
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

// ---------------------------------------------------------------------
// Instalments
// ---------------------------------------------------------------------

it('creates the instalments on the final approval of a loan, one deduction for an advance, once', function () {
    $team = pinTeam(pinCompany('Payroll Instalments Approval'));
    $service = app(EmployeeRequestService::class);

    test()->travelTo(CarbonImmutable::parse('2026-10-05 06:00:00', 'UTC'));
    $loan = EmployeeRequest::query()->create([
        'company_id'      => $team['company']->id,
        'employee_id'     => $team['employee']->id,
        'request_type_id' => EmployeeRequestType::query()->where('company_id', $team['company']->id)->where('code', EmployeeRequestKinds::LoanCode)->value('id'),
        'requested_by'    => $team['employee_user']->id,
        'amount'          => '100000',
        'payload'         => ['purpose' => 'House repair', 'instalments' => 3, 'start_month' => '2026-10'],
    ]);
    $service->submit($loan, $team['employee_user']);
    $service->approve($loan->fresh(), $team['manager_user']);

    // Not yet finally approved: nothing is recorded.
    expect(RequestInstalment::query()->where('request_id', $loan->id)->count())->toBe(0);

    $service->approve($loan->fresh(), $team['hr']);
    $rows = RequestInstalment::query()->where('request_id', $loan->id)->orderBy('number')->get();

    expect($rows->pluck('due_month')->all())->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($rows->map(fn ($row): string => number_format((float) $row->amount, 2, '.', ''))->all())->toBe(['33333.33', '33333.33', '33333.34'])
        ->and($rows->pluck('status')->unique()->all())->toBe([RequestInstalment::Due])
        ->and($rows->pluck('kind')->unique()->all())->toBe([RequestInstalment::KindLoan])
        ->and($rows->pluck('company_id')->unique()->all())->toBe([$team['company']->id])
        ->and(RequestInstalmentEvent::query()->where('request_id', $loan->id)->where('action', 'created')->value('user_id'))->toBe($team['hr']->id);

    // Synchronising the approved request again does not duplicate anything.
    $service->synchronize($loan->fresh());
    $this->instalments->createForApprovedRequest($loan->fresh());
    expect(RequestInstalment::query()->where('request_id', $loan->id)->count())->toBe(3)
        ->and(RequestInstalmentEvent::query()->where('request_id', $loan->id)->count())->toBe(1);

    $advance = pinApproved($team, EmployeeRequestKinds::SalaryAdvanceCode, '25000', ['reason' => 'School fees', 'deduct_month' => '2026-11']);
    $deduction = RequestInstalment::query()->where('request_id', $advance->id)->sole();

    expect($deduction->kind)->toBe(RequestInstalment::KindAdvance)
        ->and($deduction->due_month)->toBe('2026-11')
        ->and((float) $deduction->amount)->toBe(25000.0)
        ->and($deduction->status)->toBe(RequestInstalment::Due);
});

it('backfills approved loans and advances without instalments, idempotently, and ignores the rest', function () {
    $team = pinTeam(pinCompany('Payroll Instalments Backfill'));
    $typeId = fn (string $code): int => (int) EmployeeRequestType::query()->where('company_id', $team['company']->id)->where('code', $code)->value('id');
    $make = fn (string $code, string $status, string $amount, array $payload): EmployeeRequest => EmployeeRequest::query()->create([
        'company_id'      => $team['company']->id,
        'employee_id'     => $team['employee']->id,
        'request_type_id' => $typeId($code),
        'requested_by'    => $team['employee_user']->id,
        'amount'          => $amount,
        'status'          => $status,
        'payload'         => $payload,
    ]);

    $loan = $make(EmployeeRequestKinds::LoanCode, 'approved', '60000', ['purpose' => 'Wedding', 'instalments' => 6, 'start_month' => '2026-09',
        'schedule'                                                                => EmployeeRequestKinds::instalmentSchedule('60000', 6, '2026-09')]);
    $noSchedule = $make(EmployeeRequestKinds::LoanCode, 'approved', '9000', ['purpose' => 'Phone', 'instalments' => 3, 'start_month' => '2026-12']);
    $advance = $make(EmployeeRequestKinds::SalaryAdvanceCode, 'approved', '15000', ['reason' => 'Rent', 'deduct_month' => '2026-10']);
    $pending = $make(EmployeeRequestKinds::LoanCode, 'pending_approval', '50000', ['purpose' => 'Car', 'instalments' => 5, 'start_month' => '2026-12']);
    $rejected = $make(EmployeeRequestKinds::SalaryAdvanceCode, 'rejected', '5000', ['reason' => 'Trip', 'deduct_month' => '2026-11']);

    expect($this->instalments->backfill($team['company']->id))->toBe(3)
        ->and(RequestInstalment::query()->where('request_id', $loan->id)->count())->toBe(6)
        ->and(RequestInstalment::query()->where('request_id', $loan->id)->orderBy('number')->value('due_month'))->toBe('2026-09')
        ->and(RequestInstalment::query()->where('request_id', $noSchedule->id)->pluck('due_month')->all())->toBe(['2026-12', '2027-01', '2027-02'])
        ->and(RequestInstalment::query()->where('request_id', $advance->id)->sole()->due_month)->toBe('2026-10')
        ->and(RequestInstalment::query()->whereIn('request_id', [$pending->id, $rejected->id])->exists())->toBeFalse()
        ->and(RequestInstalmentEvent::query()->where('request_id', $loan->id)->sole()->only(['action', 'user_id']))->toBe(['action' => 'backfilled', 'user_id' => null]);

    // Running it again changes nothing.
    expect($this->instalments->backfill($team['company']->id))->toBe(0)
        ->and(RequestInstalment::query()->where('company_id', $team['company']->id)->count())->toBe(10)
        ->and(RequestInstalmentEvent::query()->where('company_id', $team['company']->id)->count())->toBe(3);
});

it('marks an instalment deducted, skips one carrying it forward, undoes a deduction, and audits each change', function () {
    $team = pinTeam(pinCompany('Payroll Instalments Changes'));
    [$loan] = pinLoanAndAdvance($team);
    $rows = RequestInstalment::query()->where('request_id', $loan->id)->orderBy('number')->get();
    $hr = $team['hr'];

    $deducted = $this->instalments->markDeducted($rows[0], $hr, 'October payroll');
    expect($deducted->status)->toBe(RequestInstalment::Deducted)
        ->and($deducted->status_changed_by)->toBe($hr->id)
        ->and($deducted->note)->toBe('October payroll');

    // Not twice, and never before the instalment's month.
    expect(fn () => $this->instalments->markDeducted($rows[0]->fresh(), $hr))->toThrow(RuntimeException::class, 'only a due instalment')
        ->and(fn () => $this->instalments->markDeducted($rows[2], $hr))->toThrow(RuntimeException::class, 'before that month');

    $skipped = $this->instalments->skip($rows[1], $hr, 'Unpaid leave in November', true);
    $carried = RequestInstalment::query()->where('request_id', $loan->id)->where('number', 4)->sole();

    expect($skipped->status)->toBe(RequestInstalment::Skipped)
        ->and($carried->only(['due_month', 'status']))->toBe(['due_month' => '2027-01', 'status' => RequestInstalment::Due])
        ->and((float) $carried->amount)->toBe((float) $rows[1]->amount)
        ->and($carried->note)->toContain('Carried forward from instalment #2');

    $reverted = $this->instalments->revertToDue($deducted, $hr, 'Marked by mistake');
    expect($reverted->status)->toBe(RequestInstalment::Due)
        ->and($reverted->note)->toBe('Deduction undone: Marked by mistake')
        ->and(RequestInstalmentEvent::query()->where('request_id', $loan->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['created', 'deducted', 'carried_forward', 'skipped', 'reverted']);

    $skipEvent = RequestInstalmentEvent::query()->where('request_id', $loan->id)->where('action', 'skipped')->sole();
    expect($skipEvent->user_id)->toBe($hr->id)
        ->and($skipEvent->reason)->toBe('Unpaid leave in November')
        ->and($skipEvent->before[0]['status'])->toBe('due')
        ->and($skipEvent->after[0]['status'])->toBe('skipped');

    // Nobody without the payroll permission, and HR never on their own loan.
    expect($this->instalments->canManage($team['manager_user'], $loan))->toBeFalse()
        ->and(fn () => $this->instalments->markDeducted($rows[0]->fresh(), $team['manager_user']))->toThrow(AuthorizationException::class);

    $hrEmployee = pinEmployee($team['company'], 'HR Herself', ['user_id' => $hr->id]);
    $own = EmployeeRequest::query()->create([
        'company_id'      => $team['company']->id,
        'employee_id'     => $hrEmployee->id,
        'request_type_id' => $loan->request_type_id,
        'requested_by'    => $hr->id,
        'amount'          => '5000',
        'status'          => 'approved',
        'payload'         => ['purpose' => 'Own', 'instalments' => 1, 'start_month' => '2026-10'],
    ]);
    $ownRow = $this->instalments->createForApprovedRequest($own)->first();

    expect($this->instalments->canManage($hr, $own))->toBeFalse()
        ->and(fn () => $this->instalments->markDeducted($ownRow, $hr))->toThrow(AuthorizationException::class);
});

it('reschedules what is left after the last deducted month and settles a loan early, with an audit', function () {
    $team = pinTeam(pinCompany('Payroll Instalments Reschedule'));
    test()->travelTo(CarbonImmutable::parse('2026-10-05 06:00:00', 'UTC'));
    $loan = pinApproved($team, EmployeeRequestKinds::LoanCode, '60000', ['purpose' => 'Plot', 'instalments' => 6, 'start_month' => '2026-10']);
    test()->travelTo(CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC'));
    $hr = $team['hr'];

    $first = RequestInstalment::query()->where('request_id', $loan->id)->where('number', 1)->sole();
    $this->instalments->markDeducted($first, $hr);

    expect(fn () => $this->instalments->reschedule($loan, $hr, '2026-10', 4, 'Lower instalments'))
        ->toThrow(RuntimeException::class, 'after October 2026');

    $rows = $this->instalments->reschedule($loan, $hr, '2026-12', 4, 'Lower instalments after a pay cut');
    $due = $rows->where('status', RequestInstalment::Due)->values();

    expect($rows)->toHaveCount(5)
        ->and($rows->firstWhere('number', 1)->status)->toBe(RequestInstalment::Deducted)
        ->and($due->pluck('number')->all())->toBe([2, 3, 4, 5])
        ->and($due->pluck('due_month')->all())->toBe(['2026-12', '2027-01', '2027-02', '2027-03'])
        ->and($due->map(fn ($row): float => (float) $row->amount)->all())->toBe([12500.0, 12500.0, 12500.0, 12500.0]);

    $event = RequestInstalmentEvent::query()->where('request_id', $loan->id)->where('action', 'rescheduled')->sole();
    expect($event->reason)->toBe('Lower instalments after a pay cut')
        ->and($event->user_id)->toBe($hr->id)
        ->and(count($event->before))->toBe(5)
        ->and(count($event->after))->toBe(4);

    expect($this->instalments->settle($loan, $hr, 'Paid the balance in cash on 4 Nov'))->toBe(4);

    $statuses = RequestInstalment::query()->where('request_id', $loan->id)->pluck('status', 'number')->all();
    $total = RequestInstalment::query()->where('request_id', $loan->id)->whereIn('status', [RequestInstalment::Deducted, RequestInstalment::Settled])->sum('amount');

    expect($statuses)->toBe([1 => 'deducted', 2 => 'settled', 3 => 'settled', 4 => 'settled', 5 => 'settled'])
        ->and((float) $total)->toBe(60000.0)
        ->and(RequestInstalmentEvent::query()->where('request_id', $loan->id)->where('action', 'settled')->sole()->reason)->toBe('Paid the balance in cash on 4 Nov')
        ->and(fn () => $this->instalments->settle($loan, $hr, 'Again'))->toThrow(RuntimeException::class, 'nothing to settle')
        ->and(fn () => $this->instalments->reschedule($loan, $hr, '2027-05', 2, 'Again'))->toThrow(RuntimeException::class, 'Nothing is left');
});

it('shows the schedule with statuses on the request, on the employee page for HR, and to the employee', function () {
    $team = pinTeam(pinCompany('Payroll Instalments Screens'));
    [$loan, $advance] = pinLoanAndAdvance($team);
    $october = RequestInstalment::query()->where('request_id', $loan->id)->where('number', 1)->sole();
    $december = RequestInstalment::query()->where('request_id', $loan->id)->where('number', 3)->sole();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Auth::login($team['hr']);

    Livewire::test(RequestInstalmentsRelationManager::class, ['ownerRecord' => $team['employee'], 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords([$october, $december])
        ->assertActionVisible(TestAction::make('markDeducted')->table($october))
        ->callAction(TestAction::make('markDeducted')->table($october), ['note' => 'October payroll'])
        ->assertNotified('Instalment marked as deducted')
        ->callAction(TestAction::make('markDeducted')->table($december))
        ->assertNotified('Not changed');

    expect($october->fresh()->status)->toBe(RequestInstalment::Deducted)
        ->and($december->fresh()->status)->toBe(RequestInstalment::Due);

    // The employee sees their own schedule (read-only) on the request and on their page.
    Auth::login($team['employee_user']);
    $record = EmployeeRequestResource::getEloquentQuery()->findOrFail($loan->id);

    expect(EmployeeRequestResource::instalmentScheduleHtml($record))->toContain('Deducted', 'Due', 'October 2026', '10,000.00')
        ->and(app(RequestInstalmentService::class)->scheduleHtml($advance))->toContain('20,000.00', 'Due')
        ->and(RequestInstalmentsRelationManager::canViewForRecord($team['employee'], ViewEmployee::class))->toBeTrue()
        ->and(RequestInstalmentsRelationManager::canViewForRecord($team['manager'], ViewEmployee::class))->toBeFalse();

    Livewire::test(RequestInstalmentsRelationManager::class, ['ownerRecord' => $team['employee'], 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords([$december])
        ->assertActionHidden(TestAction::make('markDeducted')->table($december))
        ->assertActionHidden(TestAction::make('settle')->table($december));
});

// ---------------------------------------------------------------------
// Payroll input export
// ---------------------------------------------------------------------

it('exports one row per employee of the month with attendance, leave, overtime and deductions, from the snapshot once finalised', function () {
    $company = pinCompany('Payroll Input October');
    $team = pinTeam($company);
    pinLoanAndAdvance($team);
    pinOctober($team);
    pinEmployee($company, 'Zara Joiner', ['joining_date' => '2026-10-15']);
    pinEmployee($company, 'Omar Leaver', ['joining_date' => '2025-01-01', 'departure_date' => '2026-10-09', 'leaving_date' => '2026-10-09', 'is_active' => false]);
    pinEmployee($company, 'Gone In September', ['departure_date' => '2026-09-30', 'is_active' => false]);
    pinEmployee($company, 'Starts In November', ['joining_date' => '2026-11-02']);

    $live = $this->payroll->build($team['hr'], $company->id, '2026-10');
    $bilal = pinRow($live, 'Bilal Ahmed');

    expect(collect($live['rows'])->pluck('name')->all())->toBe(['Bilal Ahmed', 'Line Manager', 'Omar Leaver', 'Zara Joiner'])
        ->and($live['draft'])->toBeTrue()
        ->and($bilal)->toMatchArray([
            'code'              => 'PIN-'.$company->id.'-01',
            'designation'       => 'Dispatcher',
            'joining_date'      => '2024-03-01',
            'leaving_date'      => null,
            'base_salary'       => 100000.0,
            'working_days'      => 22,
            'present_days'      => 19,
            'absent_days'       => 1,
            'paid_leave_days'   => 1.0,
            'unpaid_leave_days' => 1.5,
            'half_days'         => 1,
            'late_count'        => 1,
            'late_minutes'      => 5,
            'ot_weekday_hours'  => 2.0,
            'ot_weekend_hours'  => 4.0,
            'loan_due'          => 10000.0,
            'advance_due'       => 20000.0,
        ])
        ->and($bilal['notes'])->toContain('Month not finalised')
        ->and(pinRow($live, 'Zara Joiner'))->toMatchArray(['working_days' => 12, 'absent_days' => 12])
        ->and(pinRow($live, 'Zara Joiner')['notes'])->toContain('Joined mid-month (15 Oct 2026)')
        ->and(pinRow($live, 'Omar Leaver'))->toMatchArray(['working_days' => 7, 'leaving_date' => '2026-10-09'])
        ->and(pinRow($live, 'Omar Leaver')['notes'])->toContain('Left on 09 Oct 2026')
        ->and($live['totals'])->toMatchArray(['working_days' => 22.0 + 22 + 7 + 12, 'loan_due' => 10000.0, 'advance_due' => 20000.0, 'unpaid_leave_days' => 1.5])
        ->and($live['deductions'])->toBe(['due_count' => 2, 'due_amount' => 30000.0]);

    app(AttendanceMonthLockService::class)->finalise($team['hr'], $company->id, '2026-10');
    $late = pinEmployee($company, 'Added After Finalising', ['joining_date' => '2026-10-26']);

    // Something that changes a live calculation: the export keeps the frozen figures.
    config(['attendance.monthly_report.daily_required_minutes' => 420]);
    $frozen = $this->payroll->build($team['hr'], $company->id, '2026-10');
    $snapshot = app(AttendanceMonthLockService::class)->snapshotsFor($company->id, '2026-10', [$team['employee']->id])[$team['employee']->id];
    $recalculated = collect(app(MonthlyAttendanceReport::class)->liveCompanyMonth($company->id, '2026-10'))->firstWhere('employee.id', $team['employee']->id);

    expect($frozen['draft'])->toBeFalse()
        ->and($frozen['finalised']['by'])->toBe($team['hr']->name)
        ->and(pinRow($frozen, 'Bilal Ahmed')['grace_balance'])->toBe((int) $snapshot['summary']['grace_balance_minutes'])
        ->and(pinRow($frozen, 'Bilal Ahmed')['grace_balance'])->not->toBe((int) $recalculated['summary']['grace_balance_minutes'])
        ->and(pinRow($frozen, 'Bilal Ahmed')['notes'])->not->toContain('Month not finalised')
        ->and(collect(pinRow($frozen, 'Bilal Ahmed'))->except(['grace_balance', 'notes'])->all())->toBe(collect($bilal)->except(['grace_balance', 'notes'])->all())
        ->and(pinRow($frozen, $late->name)['notes'])->toContain('Not in the finalised snapshot');
});

it('hides CNIC, bank account and base salary without the sensitive-data permission', function () {
    $company = pinCompany('Payroll Input Sensitive');
    $team = pinTeam($company);
    $account = BankAccount::query()->create([
        'account_number'      => 'PK36SCBL'.random_int(1000000000, 9999999999),
        'account_holder_name' => 'Bilal Ahmed',
        'partner_id'          => $team['employee']->partner_id,
        'bank_id'             => Bank::query()->value('id'),
    ]);
    $team['employee']->update(['bank_account_id' => $account->id]);
    $plainHr = pinPayrollHr($company, false);

    $full = $this->payroll->build($team['hr'], $company->id, '2026-10');
    $plain = $this->payroll->build($plainHr, $company->id, '2026-10');

    expect(array_keys($full['columns']))->toContain('cnic', 'bank_account', 'base_salary')
        ->and(pinRow($full, 'Bilal Ahmed'))->toMatchArray(['cnic' => $team['employee']->fresh()->cnic, 'bank_account' => $account->account_number, 'base_salary' => 100000.0])
        ->and(array_values(array_intersect(['cnic', 'bank_account', 'base_salary'], array_keys($plain['columns']))))->toBe([])
        ->and(pinRow($plain, 'Bilal Ahmed'))->not->toHaveKeys(['cnic', 'bank_account', 'base_salary'])
        ->and($plain['totals'])->not->toHaveKey('base_salary');

    $headers = pinWorkbook($plain)->getActiveSheet()->rangeToArray('A6:Z6')[0];
    expect(array_values(array_intersect(['CNIC', 'Bank account', 'Base salary'], $headers)))->toBe([])
        ->and($headers)->toContain('Employee code', 'Unpaid leave days', 'Loan instalment due');

    $fullHeaders = pinWorkbook($full)->getActiveSheet()->rangeToArray('A6:Z6')[0];
    expect($fullHeaders)->toContain('CNIC', 'Bank account', 'Base salary');
});

it('marks DRAFT in the title, sheet and file name of a month that is not finalised, and styles the workbook', function () {
    $company = pinCompany('Payroll Input Draft');
    $team = pinTeam($company);
    pinLoanAndAdvance($team);
    pinPunch($team['employee'], '2026-10-01', '10:00', '18:00');
    $excel = app(PayrollInputExcel::class);

    $draft = $this->payroll->build($team['hr'], $company->id, '2026-10');
    $book = pinWorkbook($draft);
    $sheet = $book->getActiveSheet();
    $headers = $sheet->rangeToArray('A6:Z6')[0];
    $totalRow = PayrollInputExcel::HEADER_ROW + count($draft['rows']) + 1;
    $loanColumn = Coordinate::stringFromColumnIndex(array_search('Loan instalment due', $headers, true) + 1);

    expect($excel->fileName($draft))->toBe('Payroll-Input-Payroll-Input-Draft-2026-10-DRAFT.xlsx')
        ->and($book->getProperties()->getTitle())->toBe('Payroll input Payroll Input Draft October 2026 (DRAFT)')
        ->and($sheet->getTitle())->toBe('DRAFT Payroll input')
        ->and($sheet->getCell('A1')->getValue())->toBe('Payroll input (DRAFT)')
        ->and((string) $sheet->getCell('B5')->getValue())->toStartWith('DRAFT')
        ->and($sheet->getStyle('A6')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getFreezePane())->toBe('C7')
        ->and($sheet->getColumnDimension('B')->getWidth())->toEqual(26)
        ->and($sheet->getCell('A'.$totalRow)->getValue())->toBe('Total')
        ->and((float) $sheet->getCell($loanColumn.$totalRow)->getCalculatedValue())->toBe(10000.0)
        ->and($sheet->getStyle($loanColumn.'7')->getNumberFormat()->getFormatCode())->toBe('#,##0.00');

    app(AttendanceMonthLockService::class)->finalise($team['hr'], $company->id, '2026-10');
    $final = $this->payroll->build($team['hr'], $company->id, '2026-10');
    $finalSheet = pinWorkbook($final)->getActiveSheet();

    expect($excel->fileName($final))->toBe('Payroll-Input-Payroll-Input-Draft-2026-10.xlsx')
        ->and($finalSheet->getTitle())->toBe('Payroll input')
        ->and((string) $finalSheet->getCell('B5')->getValue())->toStartWith('Finalised on');

    // The page: warning banner and DRAFT download while not finalised.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Auth::login($team['hr']);
    app(AttendanceMonthLockService::class)->reopen($team['hr'], $company->id, '2026-10', 'Late leave correction from HR');

    Livewire::test(PayrollInput::class)
        ->set('filters.company_id', $company->id)
        ->set('filters.month', '2026-10')
        ->assertSee('is not finalised in the Attendance Report')
        ->assertSee('Bilal Ahmed')
        ->callAction('downloadExcel')
        ->assertFileDownloaded('Payroll-Input-Payroll-Input-Draft-2026-10-DRAFT.xlsx');

    expect(PayrollInput::canAccess())->toBeTrue();
    Auth::login($team['manager_user']);
    expect(PayrollInput::canAccess())->toBeFalse()
        ->and(fn () => $this->payroll->build($team['manager_user'], $company->id, '2026-10'))->toThrow(AuthorizationException::class);
});

it('marks the month\'s instalments deducted once, audited, and idempotently', function () {
    $company = pinCompany('Payroll Input Mark Deducted');
    $team = pinTeam($company);
    [$loan, $advance] = pinLoanAndAdvance($team);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Auth::login($team['hr']);

    Livewire::test(PayrollInput::class)
        ->set('filters.company_id', $company->id)
        ->set('filters.month', '2026-10')
        ->assertActionVisible('markDeducted')
        ->callAction('markDeducted')
        ->assertNotified('2 instalment(s) marked as deducted')
        ->assertActionHidden('markDeducted');

    $october = RequestInstalment::query()->where('company_id', $company->id)->where('due_month', '2026-10')->get();
    expect($october->pluck('status')->unique()->all())->toBe([RequestInstalment::Deducted])
        ->and($october->pluck('status_changed_by')->unique()->all())->toBe([$team['hr']->id])
        ->and(RequestInstalment::query()->where('request_id', $loan->id)->where('due_month', '2026-11')->value('status'))->toBe(RequestInstalment::Due)
        ->and(RequestInstalmentEvent::query()->where('company_id', $company->id)->where('action', 'deducted')->pluck('reason')->unique()->all())->toBe(['Payroll input October 2026']);

    // A second run changes nothing and writes no audit rows.
    expect($this->instalments->markMonthDeducted($team['hr'], $company->id, '2026-10'))->toBe(['count' => 0, 'amount' => '0.00'])
        ->and(RequestInstalmentEvent::query()->where('company_id', $company->id)->where('action', 'deducted')->count())->toBe(2);

    // The export still shows what was due that month, now noted as taken.
    $report = $this->payroll->build($team['hr'], $company->id, '2026-10');
    expect(pinRow($report, 'Bilal Ahmed'))->toMatchArray(['loan_due' => 10000.0, 'advance_due' => 20000.0])
        ->and(pinRow($report, 'Bilal Ahmed')['notes'])->toContain('Deductions already marked as taken')
        ->and($report['deductions']['due_count'])->toBe(0);

    // A month that has not started cannot be marked; an earlier unmarked instalment is flagged, not added.
    expect(fn () => $this->instalments->markMonthDeducted($team['hr'], $company->id, '2026-12'))->toThrow(RuntimeException::class, 'has not started');

    test()->travelTo(CarbonImmutable::parse('2027-01-05 06:00:00', 'UTC'));
    $december = $this->payroll->build($team['hr'], $company->id, '2026-12');
    expect(pinRow($december, 'Bilal Ahmed')['loan_due'])->toBe(10000.0)
        ->and(pinRow($december, 'Bilal Ahmed')['notes'])->toContain('1 earlier instalment(s) still due (10,000.00, from November 2026), not included');
});

it('keeps the payroll export and instalments inside their own company', function () {
    $shipLine = pinCompany('Payroll ShipLine Isolation');
    $openPort = pinCompany('Payroll OpenPort Isolation');
    $a = pinTeam($shipLine);
    $b = pinTeam($openPort);
    pinLoanAndAdvance($a);
    [$loanB] = pinLoanAndAdvance($b);
    $rowB = RequestInstalment::query()->where('request_id', $loanB->id)->where('number', 1)->sole();

    $exportA = $this->payroll->build($a['hr'], $shipLine->id, '2026-10');

    expect(collect($exportA['rows'])->pluck('code')->all())->not->toContain('PIN-'.$openPort->id.'-01')
        ->and($exportA['deductions'])->toBe(['due_count' => 2, 'due_amount' => 30000.0])
        ->and(fn () => $this->payroll->build($a['hr'], $openPort->id, '2026-10'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->instalments->markMonthDeducted($a['hr'], $openPort->id, '2026-10'))->toThrow(AuthorizationException::class)
        ->and($this->instalments->canManage($a['hr'], $loanB))->toBeFalse()
        ->and(fn () => $this->instalments->markDeducted($rowB, $a['hr']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->instalments->settle($loanB, $a['hr'], 'Not mine'))->toThrow(AuthorizationException::class)
        ->and($this->instalments->monthFor($shipLine->id, '2026-10', [$b['employee']->id]))->toBe([]);

    // Marking ShipLine's month leaves OpenPort's instalments due.
    expect($this->instalments->markMonthDeducted($a['hr'], $shipLine->id, '2026-10', [$b['employee']->id, $a['employee']->id])['count'])->toBe(2)
        ->and($rowB->fresh()->status)->toBe(RequestInstalment::Due)
        ->and(RequestInstalment::query()->where('company_id', $openPort->id)->where('status', RequestInstalment::Deducted)->exists())->toBeFalse();
});
