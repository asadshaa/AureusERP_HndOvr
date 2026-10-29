<?php

/**
 * HR parity plan 2.2 / 2.3: effective-dated work schedules, overtime
 * requests (recorded extra, line manager -> HR approval, time-bank
 * exclusion, weekends and holidays) and the attendance month finalise /
 * lock with its snapshot, re-open audit and company isolation.
 */

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Database\Seeders\OvertimeWorkflowSeeder;
use Webkul\Employee\Exceptions\AttendanceMonthLockedException;
use Webkul\Employee\Filament\Pages\AttendanceReport;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\WorkSchedulesRelationManager;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceMonthLock;
use Webkul\Employee\Models\AttendanceMonthLockEvent;
use Webkul\Employee\Models\AttendanceMonthSnapshot;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceExcel;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\OvertimeService;
use Webkul\Employee\Services\Attendance\WorkScheduleService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    // August 2026 is over (2026-09-05 11:00 in Karachi, UTC+5).
    $this->travelTo(CarbonImmutable::parse('2026-09-05 06:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'               => '10:00',
        'attendance.monthly_report.office_end'                 => '18:00',
        'attendance.monthly_report.daily_required_minutes'     => 480,
        'attendance.monthly_report.max_outside_credit_minutes' => 60,
        'attendance.monthly_report.late_grace_minutes'         => 0,
        'attendance.monthly_report.timezone'                   => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'               => [6, 7],
        'attendance.monthly_report.show_off_days'              => false,
        'attendance.monthly_report.count_off_day_work'         => false,
        'attendance.time_bank.monthly_grace_minutes'           => 0,
        'attendance.time_bank.carry_over'                      => false,
        'attendance.notifications.late_check_in'               => false,
    ]);
    $this->report = app(MonthlyAttendanceReport::class);
    $this->locks = app(AttendanceMonthLockService::class);
});

function solCompany(string $name = 'ShipLine Schedules Test'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

function solUser(Company $company, array $permissions = [], ?string $role = null): User
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

/** HR of the company: sees and manages attendance, approves (engine "hr" route), finalises months. */
function solHr(Company $company): User
{
    return solUser($company, [
        HrPermissions::ViewAllRecords,
        HrPermissions::ViewAttendance,
        HrPermissions::ManageAttendance,
        HrPermissions::ManageEmployeeRequests,
        HrPermissions::ApproveLeave,
        HrPermissions::FinaliseAttendance,
    ], 'sol_hr');
}

function solEmployee(Company $company, string $name = 'Ayesha Khan', array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

/** An employee with a user, reporting to a line manager who has a user too. */
function solTeam(Company $company): array
{
    $managerUser = solUser($company);
    $manager = solEmployee($company, 'Line Manager', ['user_id' => $managerUser->id]);
    $employeeUser = solUser($company);
    $employee = solEmployee($company, 'Bilal Ahmed', ['user_id' => $employeeUser->id, 'parent_id' => $manager->id]);

    return ['employee' => $employee, 'employee_user' => $employeeUser, 'manager' => $manager, 'manager_user' => $managerUser, 'hr' => solHr($company)];
}

/** Punches in Karachi local time ("H:i"); null = no check-out. */
function solPunch(Employee $employee, string $date, string $in, ?string $out): AttendanceRecord
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

function solRow(MonthlyAttendanceReport $report, Employee $employee, string $date): array
{
    return collect($report->forEmployee($employee, (int) $employee->company_id, substr($date, 0, 7))['rows'])->keyBy('date')[$date];
}

function solSchedule(Employee $employee, array $attributes): WorkSchedule
{
    return WorkSchedule::query()->create($attributes + [
        'company_id'       => $employee->company_id,
        'employee_id'      => $employee->id,
        'start_time'       => '10:00',
        'end_time'         => '18:00',
        'required_minutes' => 480,
        'working_days'     => [1, 2, 3, 4, 5],
        'flexible_minutes' => 0,
    ]);
}

/** Overtime filed by the employee, approved by the line manager, then by HR. */
function solApprovedOvertime(array $team, string $date, int $minutes, ?int $hrMinutes = null): EmployeeRequest
{
    $service = app(OvertimeService::class);
    $request = $service->request($team['employee'], $team['employee_user'], $date, $minutes, 'Truck loading');
    $service->approve($request->fresh(), $team['manager_user']);

    return $service->approve($request->fresh(), $team['hr'], $hrMinutes)->fresh();
}

function solLeaveType(Company $company): LeaveType
{
    return LeaveType::query()->firstOrCreate(
        ['company_id' => $company->id, 'name' => 'Unpaid Leave (lock test)'],
        ['is_active' => true, 'requires_allocation' => 'no', 'request_unit' => 'day', 'time_type' => 'leave'],
    );
}

// ---------------------------------------------------------------------
// Work schedules
// ---------------------------------------------------------------------

it('resolves the schedule by date: the company default, then each assigned period, keeping history', function () {
    $company = solCompany();
    $hr = solHr($company);
    $employee = solEmployee($company);
    $resolver = app(AttendanceScheduleResolver::class);
    $schedules = app(WorkScheduleService::class);

    $august = $schedules->assign($hr, $employee, [
        'start_time'     => '09:00', 'end_time' => '16:00', 'required_minutes' => 420, 'working_days' => [1, 2, 3, 4, 5, 6],
        'effective_from' => '2026-08-17',
    ]);
    $september = $schedules->assign($hr, $employee, [
        'start_time'       => '08:00', 'end_time' => '17:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5],
        'flexible_minutes' => 30, 'effective_from' => '2026-09-01',
    ]);

    // The open-ended August schedule was ended the day before (history kept, no overlap).
    expect($august->fresh()->effective_to->toDateString())->toBe('2026-08-31')
        ->and(WorkSchedule::query()->where('employee_id', $employee->id)->count())->toBe(2);

    $default = $resolver->scheduleFor($employee, '2026-08-10');
    $mid = $resolver->scheduleFor($employee, '2026-08-22');
    $later = $resolver->scheduleFor($employee, '2026-09-02');

    expect($default->isDefault())->toBeTrue()
        ->and($default->startClock())->toBe('10:00')
        ->and($default->requiredMinutes)->toBe(480)
        ->and($default->isWorkingDay('2026-08-08'))->toBeFalse()
        ->and($mid->scheduleId)->toBe($august->id)
        ->and($mid->startClock().'-'.$mid->endClock())->toBe('09:00-16:00')
        ->and($mid->isWorkingDay('2026-08-22'))->toBeTrue() // a Saturday under Mon-Sat
        ->and($later->scheduleId)->toBe($september->id)
        ->and($later->flexibleMinutes)->toBe(30);

    // The check-in's scheduled window follows the schedule too (Karachi = UTC+5).
    [$start, $end] = $resolver->scheduledWindowFor($employee, '2026-08-18');
    expect($start->toDateTimeString())->toBe('2026-08-18 04:00:00')
        ->and($end->toDateTimeString())->toBe('2026-08-18 11:00:00')
        ->and($resolver->scheduledWindowFor($employee, '2026-08-23'))->toBe([null, null]); // Sunday, not a working day

    // Periods never overlap.
    expect(fn () => solSchedule($employee, ['effective_from' => '2026-08-20', 'effective_to' => '2026-08-25']))
        ->toThrow(ValidationException::class);
});

it('assigns schedules on the employee\'s Work schedule tab and in bulk from the employee list', function () {
    $company = solCompany('Truck It In Schedule UI');
    $hr = solUser($company, [
        HrPermissions::ViewAllRecords, HrPermissions::ManageAttendance, 'view_any_employee_employee', 'view_employee_employee', 'update_employee_employee',
    ], 'sol_hr_schedules');
    $one = solEmployee($company, 'Schedule One');
    $two = solEmployee($company, 'Schedule Two');
    $form = ['start_time' => '09:00', 'end_time' => '17:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5], 'flexible_minutes' => 15, 'effective_from' => '2026-09-07'];

    Auth::login($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(WorkSchedulesRelationManager::class, ['ownerRecord' => $one, 'pageClass' => ViewEmployee::class])
        ->callAction(TestAction::make('create')->table(), $form)
        ->assertHasNoFormErrors()
        // A second, overlapping period is refused with a message; nothing is saved.
        ->callAction(TestAction::make('create')->table(), ['effective_from' => '2026-09-01', 'effective_to' => '2026-09-20'] + $form)
        ->assertNotified('Schedule not saved');

    expect(WorkSchedule::query()->where('employee_id', $one->id)->count())->toBe(1);

    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('assign_work_schedule', [$one, $two], ['effective_from' => '2026-10-01', 'start_time' => '08:00'] + $form)
        ->assertNotified('Schedule assigned to 2 employee(s)');

    // One's September schedule was ended the day before the new one; history is kept.
    expect(WorkSchedule::query()->where('employee_id', $one->id)->orderBy('effective_from')->get()->map(fn (WorkSchedule $s): string => $s->effective_from->toDateString().'..'.($s->effective_to?->toDateString() ?? ''))->all())
        ->toBe(['2026-09-07..2026-09-30', '2026-10-01..'])
        ->and(app(AttendanceScheduleResolver::class)->scheduleFor($two, '2026-10-05')->startClock())->toBe('08:00');
});

it('uses each day\'s schedule in the monthly report when the schedule changes mid-month', function () {
    $company = solCompany();
    $employee = solEmployee($company);
    solSchedule($employee, ['start_time' => '09:00', 'end_time' => '16:00', 'required_minutes' => 420, 'working_days' => [1, 2, 3, 4, 5, 6], 'effective_from' => '2026-08-17']);

    solPunch($employee, '2026-08-03', '10:00', '18:00'); // default 10-6
    solPunch($employee, '2026-08-04', '10:30', '18:00'); // default: 30 late
    solPunch($employee, '2026-08-17', '09:00', '16:00'); // new schedule 9-4, 7h
    solPunch($employee, '2026-08-18', '10:00', '16:00'); // new schedule: 60 late
    solPunch($employee, '2026-08-22', '09:00', '16:00'); // Saturday is a working day now

    $result = $this->report->forEmployee($employee, $company->id, '2026-08');
    $rows = collect($result['rows'])->keyBy('date');

    expect($rows['2026-08-03']['required_seconds'])->toBe(480 * 60)
        ->and($rows['2026-08-03']['net_seconds'])->toBe(0)
        ->and($rows['2026-08-03']['schedule'])->toBe('10:00–18:00')
        ->and($rows['2026-08-04']['late_minutes'])->toBe(30)
        ->and($rows['2026-08-17']['required_seconds'])->toBe(420 * 60)
        ->and($rows['2026-08-17']['final_seconds'])->toBe(420 * 60)
        ->and($rows['2026-08-17']['late'])->toBeFalse()
        ->and($rows['2026-08-17']['schedule'])->toBe('09:00–16:00')
        ->and($rows['2026-08-18']['late_minutes'])->toBe(60)
        ->and($rows['2026-08-22']['working'])->toBeTrue()
        ->and($rows['2026-08-22']['type'])->toBe('working')
        ->and($rows->has('2026-08-08'))->toBeFalse(); // a Saturday under the default stays a weekend

    // Required: 10 weekdays x 8h (Aug 3-14) + 13 Mon-Sat days x 7h (Aug 17-31).
    expect($result['summary']['required_hours'])->toBe(round((10 * 480 + 13 * 420) / 60, 2))
        ->and($result['schedule']['start'])->toBe('09:00')
        ->and($result['schedule']['changes_in_month'])->toBeTrue()
        ->and($this->report->dailyHeaders(60, $result['schedule'])[4])->toBe('Checkin with 9 or after');
});

it('lets a flexible schedule start later without counting it as late', function () {
    $company = solCompany();
    $employee = solEmployee($company);
    solSchedule($employee, ['flexible_minutes' => 60, 'effective_from' => '2026-08-01']);

    solPunch($employee, '2026-08-03', '10:40', '18:40'); // inside the flexible hour: day 10:40-18:40
    solPunch($employee, '2026-08-04', '11:20', '19:00'); // 20 min past the flexible hour

    expect(solRow($this->report, $employee, '2026-08-03'))->toMatchArray(['late' => false, 'late_minutes' => 0, 'left_early_minutes' => 0, 'net_seconds' => 0])
        ->and(solRow($this->report, $employee, '2026-08-04'))->toMatchArray(['late' => true, 'late_minutes' => 20, 'left_early_minutes' => 0]);
});

// ---------------------------------------------------------------------
// Overtime
// ---------------------------------------------------------------------

it('provisions the overtime request type and line manager -> HR workflow for every new company, idempotently', function () {
    $company = solCompany('OpenPort Overtime Test');
    app(OvertimeWorkflowSeeder::class)->provisionForCompany($company);

    $types = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', OvertimeService::RequestCode)->get();
    $workflows = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', OvertimeService::ApprovalRequestType)->with('steps')->get();

    expect($types)->toHaveCount(1)
        ->and($types->first()->category)->toBe(OvertimeService::Category)
        ->and($workflows)->toHaveCount(1)
        ->and($workflows->first()->steps->pluck('hierarchy_route')->all())->toBe(['requester_manager', 'hr']);
});

it('shows the recorded extra minutes and validates an overtime request server-side', function () {
    $company = solCompany();
    $team = solTeam($company);
    $service = app(OvertimeService::class);
    solPunch($team['employee'], '2026-08-03', '10:00', '20:10'); // 8h required, 10h10 worked
    solPunch($team['employee'], '2026-08-04', '10:00', '18:20'); // only 20 extra

    expect($service->dayDetails($team['employee'], '2026-08-03'))->toMatchArray([
        'type' => 'working', 'recorded_extra_minutes' => 130, 'requestable_minutes' => 120, 'worked_minutes' => 610, 'required_minutes' => 480,
    ]);

    expect(fn () => $service->request($team['employee'], $team['employee_user'], '2026-08-03', 20))->toThrow(RuntimeException::class, 'at least 30')
        ->and(fn () => $service->request($team['employee'], $team['employee_user'], '2026-08-03', 150))->toThrow(RuntimeException::class, 'At most 120')
        ->and(fn () => $service->request($team['employee'], $team['employee_user'], '2026-08-04', 30))->toThrow(RuntimeException::class, 'Only 20 extra minutes')
        ->and(fn () => $service->request($team['employee'], $team['employee_user'], '2026-09-06', 60))->toThrow(RuntimeException::class, 'already been worked');

    // 125 is rounded down to 120; the day's figures are kept on the request.
    $request = $service->request($team['employee'], $team['employee_user'], '2026-08-03', 125);
    expect($request->status)->toBe('pending_approval')
        ->and($request->payload)->toMatchArray(['attendance_date' => '2026-08-03', 'requested_minutes' => 120, 'recorded_extra_minutes' => 130, 'day_type' => 'working'])
        ->and($request->title)->toBe('Overtime - 03 Aug 2026');

    // One active request per employee and day, whoever files it.
    expect(fn () => $service->request($team['employee'], $team['employee_user'], '2026-08-03', 60))->toThrow(RuntimeException::class, 'already an overtime request')
        ->and(fn () => $service->request($team['employee'], $team['manager_user'], '2026-08-03', 60))->toThrow(RuntimeException::class, 'already an overtime request');

    // A colleague outside the employee's reporting line cannot file it.
    $colleague = solUser($company);
    solEmployee($company, 'Colleague', ['user_id' => $colleague->id]);
    expect(fn () => $service->request($team['employee'], $colleague, '2026-08-03', 60))->toThrow(RuntimeException::class, 'hierarchy');
});

it('routes overtime to the line manager, then HR, and never approves more than recorded', function () {
    $company = solCompany();
    $team = solTeam($company);
    $service = app(OvertimeService::class);
    $engine = app(ApprovalEngine::class);
    $record = solPunch($team['employee'], '2026-08-03', '10:00', '20:10'); // 130 extra

    $request = $service->request($team['employee'], $team['manager_user'], '2026-08-03', 120); // filed by the line manager
    $approval = $request->approvalRequest;

    expect($approval->request_type)->toBe('overtime')
        ->and($engine->resolveAuthorizedApprovers($approval)->pluck('id')->all())->toBe([$team['manager_user']->id])
        ->and($engine->canAct($approval, $team['hr']))->toBeFalse()
        ->and($engine->canAct($approval, $team['employee_user']))->toBeFalse();

    $service->approve($request->fresh(), $team['manager_user']);
    $approval = $approval->fresh();

    expect($approval->status)->toBe('pending')
        ->and($approval->current_step_sequence)->toBe(2)
        ->and($engine->canAct($approval, $team['hr']))->toBeTrue()
        ->and($engine->canAct($approval, $team['manager_user']))->toBeFalse();

    // HR cannot raise it or go below the minimum.
    expect(fn () => $service->approve($request->fresh(), $team['hr'], 135))->toThrow(RuntimeException::class, 'more than the 120 minutes requested')
        ->and(fn () => $service->approve($request->fresh(), $team['hr'], 20))->toThrow(RuntimeException::class, 'at least 30');

    // Attendance was corrected after the request: only 45 extra minutes are recorded now.
    Auth::login($team['hr']);
    app(GeofencedAttendanceService::class)->recordHrCorrection($record, $team['hr'], [
        'check_out' => CarbonImmutable::parse('2026-08-03 18:45', 'Asia/Karachi')->utc()->toDateTimeString(),
    ], 'Left at 18:45 according to the gate log');

    $approved = $service->approve($request->fresh(), $team['hr'])->fresh();

    expect($approved->status)->toBe('approved')
        ->and($approved->payload['approved_minutes'])->toBe(45)
        ->and($approved->payload['approved_recorded_extra_minutes'])->toBe(45)
        ->and(OvertimeService::minutesByDay($company->id, [$team['employee']->id], '2026-08-01', '2026-08-31')['approved'])->toBe([$team['employee']->id => ['2026-08-03' => 45]]);
});

it('takes approved overtime out of the day\'s time bank so it is not counted twice', function () {
    $company = solCompany();
    $team = solTeam($company);
    solPunch($team['employee'], '2026-08-03', '09:00', '20:00'); // 11h: 8h inside, 3h outside
    solPunch($team['employee'], '2026-08-04', '10:00', '19:00'); // 1h outside, pending overtime

    $before = solRow($this->report, $team['employee'], '2026-08-03');
    expect($before)->toMatchArray(['credit_minutes' => 60, 'net_seconds' => 3600, 'extra_minutes' => 60, 'recorded_extra_minutes' => 180, 'overtime_minutes' => 0]);

    solApprovedOvertime($team, '2026-08-03', 180);
    app(OvertimeService::class)->request($team['employee'], $team['employee_user'], '2026-08-04', 60);

    $after = solRow($this->report, $team['employee'], '2026-08-03');
    expect($after)->toMatchArray([
        'overtime_minutes'       => 180,
        'recorded_extra_minutes' => 180, // still what attendance recorded
        'credit_minutes'         => 0,
        'final_seconds'          => 480 * 60,
        'net_seconds'            => 0,
        'extra_minutes'          => 0,
    ]);

    $summary = $this->report->forEmployee($team['employee'], $company->id, '2026-08')['summary'];
    expect($summary['overtime_approved_hours'])->toBe(3.0)
        ->and($summary['overtime_pending_hours'])->toBe(1.0)
        // Aug 4 still earns its outside hour (pending overtime is not taken out yet).
        ->and($summary['time_bank_extra_minutes'])->toBe(60);

    // Partly approved: what is left outside the window still earns credit (up to the cap).
    $other = solTeam($company);
    solPunch($other['employee'], '2026-08-05', '09:00', '20:00');
    solApprovedOvertime($other, '2026-08-05', 120);
    expect(solRow($this->report, $other['employee'], '2026-08-05'))->toMatchArray(['overtime_minutes' => 120, 'credit_minutes' => 60, 'net_seconds' => 3600]);
});

it('counts every minute on a weekend or public holiday as overtime', function () {
    config(['attendance.monthly_report.count_off_day_work' => true]);
    $company = solCompany();
    $team = solTeam($company);
    CalendarLeave::query()->create(['name' => 'Independence Day', 'time_type' => 'leave', 'date_from' => '2026-08-14', 'date_to' => '2026-08-14', 'company_id' => $company->id]);

    solPunch($team['employee'], '2026-08-08', '11:00', '15:30'); // Saturday, 4h30
    solPunch($team['employee'], '2026-08-14', '10:00', '12:00'); // holiday, 2h

    expect(app(OvertimeService::class)->dayDetails($team['employee'], '2026-08-08'))->toMatchArray(['type' => 'weekend', 'recorded_extra_minutes' => 270])
        ->and(app(OvertimeService::class)->dayDetails($team['employee'], '2026-08-14'))->toMatchArray(['type' => 'holiday', 'recorded_extra_minutes' => 120]);

    $before = $this->report->forEmployee($team['employee'], $company->id, '2026-08')['summary'];
    expect($before['weekend_holiday_hours'])->toBe(6.5);

    solApprovedOvertime($team, '2026-08-08', 270);

    $saturday = solRow($this->report, $team['employee'], '2026-08-08');
    $summary = $this->report->forEmployee($team['employee'], $company->id, '2026-08')['summary'];

    expect($saturday)->toMatchArray(['overtime_minutes' => 270, 'off_day_seconds' => 0])
        ->and($summary['overtime_approved_hours'])->toBe(4.5)
        ->and($summary['weekend_holiday_hours'])->toBe(6.5)
        // With off-day work counting towards the time bank, the paid Saturday no longer does.
        ->and($summary['worked_seconds'] - $before['worked_seconds'])->toBe(-270 * 60);
});

// ---------------------------------------------------------------------
// Month finalise / lock
// ---------------------------------------------------------------------

it('finalises with a summary of open issues, snapshots every employee and freezes the report and export', function () {
    $company = solCompany();
    $team = solTeam($company);
    $hr = $team['hr'];
    solPunch($team['employee'], '2026-08-03', '10:00', '19:00');
    solPunch($team['employee'], '2026-08-04', '10:15', null); // missing check-out
    app(OvertimeService::class)->request($team['employee'], $team['employee_user'], '2026-08-03', 60); // pending overtime
    Leave::query()->create([
        'employee_id' => $team['employee']->id, 'company_id' => $company->id, 'holiday_status_id' => solLeaveType($company)->id,
        'state'       => 'confirm', 'request_date_from' => '2026-08-20', 'request_date_to' => '2026-08-20', 'number_of_days' => 1,
    ]); // pending leave

    $review = $this->locks->review($company->id, '2026-08');
    expect($review)->toMatchArray(['employees' => 2, 'missing_check_outs' => 1, 'days_needing_review' => 1, 'pending_leave' => 1, 'pending_overtime' => 1])
        ->and($review['warnings'])->toHaveCount(3);

    $live = $this->report->forCompany($hr, $company->id, '2026-08');
    $lock = $this->locks->finalise($hr, $company->id, '2026-08');

    expect($lock->isFinalised())->toBeTrue()
        ->and($lock->employees_count)->toBe(2)
        ->and($lock->open_issues['pending_overtime'])->toBe(1)
        ->and(AttendanceMonthSnapshot::query()->where('lock_id', $lock->id)->count())->toBe(2)
        ->and(AttendanceMonthLockEvent::query()->where('lock_id', $lock->id)->where('action', 'finalised')->where('user_id', $hr->id)->exists())->toBeTrue();

    // Something that would change a live calculation: the frozen report does not move.
    config(['attendance.monthly_report.daily_required_minutes' => 420]);
    $frozen = $this->report->forCompany($hr, $company->id, '2026-08');
    $mine = collect($frozen['employees'])->firstWhere('employee.id', $team['employee']->id);
    $liveMine = collect($live['employees'])->firstWhere('employee.id', $team['employee']->id);

    expect($frozen['finalised']['by'])->toBe($hr->name)
        ->and($mine['summary']['required_hours'])->toBe($liveMine['summary']['required_hours'])
        ->and($mine['summary']['overtime_pending_hours'])->toEqual(1)
        ->and($this->report->forEmployee($team['employee'], $company->id, '2026-08')['summary']['required_hours'])->toBe($liveMine['summary']['required_hours']);

    $path = tempnam(sys_get_temp_dir(), 'sol').'.xlsx';
    (new Xlsx(app(MonthlyAttendanceExcel::class)->build($frozen)))->save($path);
    $book = IOFactory::load($path);
    @unlink($path);

    $summary = $book->getSheetByName('Summary');
    $header = MonthlyAttendanceExcel::SUMMARY_HEADER_ROW;
    $headers = $summary->rangeToArray('A'.$header.':AA'.$header)[0];
    $row = collect(range($header + 1, $header + 2))->first(fn (int $r): bool => $summary->getCell('B'.$r)->getValue() === 'Bilal Ahmed');

    expect((string) $summary->getCell('B5')->getValue())->toStartWith('Finalised on ')->toContain($hr->name)
        ->and(array_slice($headers, -3))->toBe(['OT approved (hrs)', 'OT pending (hrs)', 'Weekend / holiday (hrs)'])
        ->and((float) $summary->getCell('N'.$row)->getValue())->toBe((float) $liveMine['summary']['required_hours'])
        ->and((float) $summary->getCell('Z'.$row)->getValue())->toBe(1.0);
});

it('blocks every write to a finalised month, whatever the path, with a clear message', function () {
    $company = solCompany();
    $team = solTeam($company);
    $hr = $team['hr'];
    Auth::login($hr);
    $record = solPunch($team['employee'], '2026-08-03', '10:00', '18:00');
    $type = solLeaveType($company);
    $pendingLeave = Leave::query()->create([
        'employee_id' => $team['employee']->id, 'company_id' => $company->id, 'holiday_status_id' => $type->id,
        'state'       => 'confirm', 'request_date_from' => '2026-08-10', 'request_date_to' => '2026-08-10', 'number_of_days' => 1,
    ]);
    $approvedLeave = Leave::query()->create([
        'employee_id' => $team['employee']->id, 'company_id' => $company->id, 'holiday_status_id' => $type->id,
        'state'       => 'validate_two', 'request_date_from' => '2026-08-11', 'request_date_to' => '2026-08-11', 'number_of_days' => 1,
    ]);

    $this->locks->finalise($hr, $company->id, '2026-08');
    $locked = AttendanceMonthLockedException::class;

    // Attendance records and intervals: create, update, delete (model, HR correction, review, interval writes).
    expect(fn () => solPunch($team['employee'], '2026-08-05', '10:00', '18:00'))->toThrow($locked, 'Attendance for August 2026')
        ->and(fn () => $record->fresh()->update(['notes' => 'late edit']))->toThrow($locked)
        ->and(fn () => $record->fresh()->delete())->toThrow($locked)
        ->and(fn () => app(GeofencedAttendanceService::class)->recordHrCorrection($record->fresh(), $hr, ['check_out' => '2026-08-03 14:00:00'], 'Correcting a finalised day'))->toThrow($locked)
        ->and(fn () => AttendanceInterval::query()->where('attendance_record_id', $record->id)->first()->update(['voided_at' => now()]))->toThrow($locked)
        ->and(fn () => AttendanceInterval::query()->create([
            'company_id' => $company->id, 'employee_id' => $team['employee']->id, 'attendance_record_id' => $record->id,
            'check_in'   => '2026-08-03 14:00:00', 'check_out' => '2026-08-03 15:00:00', 'source' => 'manual',
        ]))->toThrow($locked);

    // Leave: any state change, a change to an approved leave, a new request, deleting an approved leave.
    expect(fn () => $pendingLeave->fresh()->update(['state' => 'validate_two']))->toThrow($locked, 'leave requests for those days')
        ->and(fn () => $pendingLeave->fresh()->update(['state' => 'refuse']))->toThrow($locked)
        ->and(fn () => $approvedLeave->fresh()->update(['state' => 'cancel']))->toThrow($locked)
        ->and(fn () => $approvedLeave->fresh()->update(['request_date_to' => '2026-08-12']))->toThrow($locked)
        ->and(fn () => $approvedLeave->fresh()->delete())->toThrow($locked)
        ->and(fn () => Leave::query()->create([
            'employee_id' => $team['employee']->id, 'company_id' => $company->id, 'holiday_status_id' => $type->id,
            'state'       => 'confirm', 'request_date_from' => '2026-08-31', 'request_date_to' => '2026-09-01', 'number_of_days' => 2,
        ]))->toThrow($locked);

    // Work schedules covering it, and overtime for its days.
    expect(fn () => app(WorkScheduleService::class)->assign($hr, $team['employee'], [
        'start_time' => '09:00', 'end_time' => '17:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5], 'effective_from' => '2026-08-25',
    ]))->toThrow($locked, 'work schedules')
        ->and(fn () => app(OvertimeService::class)->request($team['employee'], $team['employee_user'], '2026-08-03', 60))->toThrow($locked, 'overtime');

    // Nothing changed.
    expect(AttendanceRecord::query()->where('employee_id', $team['employee']->id)->count())->toBe(1)
        ->and($pendingLeave->fresh()->state->value)->toBe('confirm')
        ->and($approvedLeave->fresh()->state->value)->toBe('validate_two');

    // The next month is open; a JSON caller gets a 422.
    expect(solPunch($team['employee'], '2026-09-01', '10:00', '18:00')->exists)->toBeTrue()
        ->and(app(WorkScheduleService::class)->assign($hr, $team['employee'], [
            'start_time' => '09:00', 'end_time' => '17:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5], 'effective_from' => '2026-09-01',
        ])->exists)->toBeTrue();

    try {
        solPunch($team['employee'], '2026-08-06', '10:00', '18:00');
    } catch (AttendanceMonthLockedException $e) {
        $response = $e->render(Request::create('/api/attendance', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        expect($response->status())->toBe(422)
            ->and($response->getData(true)['errors']['attendance_date'][0])->toContain('is finalised');
    }
});

it('shows the refusal as a notification on Filament screens instead of saving', function () {
    $company = solCompany('ShipLine Filament Lock');
    $team = solTeam($company);
    solPunch($team['employee'], '2026-08-03', '10:00', '18:00');
    $this->locks->finalise($team['hr'], $company->id, '2026-08');

    Auth::login($team['hr']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(ManageAttendanceRecords::class)
        ->callAction(TestAction::make('create')->table(), [
            'employee_id'     => $team['employee']->id,
            'attendance_date' => '2026-08-05',
            'check_in'        => '2026-08-05 05:00:00',
            'check_out'       => '2026-08-05 13:00:00',
            'status'          => 'present',
            'source'          => 'manual',
        ])
        ->assertNotified('Attendance month is finalised');

    expect(AttendanceRecord::query()->where('employee_id', $team['employee']->id)->whereDate('attendance_date', '2026-08-05')->exists())->toBeFalse();
});

it('re-opens only with the permission and a reason, audits it, and replaces the snapshot on the next finalise', function () {
    $company = solCompany();
    $team = solTeam($company);
    $hr = $team['hr'];
    $viewer = solUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'sol_hr_viewer');
    solPunch($team['employee'], '2026-08-03', '10:00', '18:00');

    expect(fn () => $this->locks->finalise($viewer, $company->id, '2026-08'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->locks->finalise($hr, $company->id, '2026-09'))->toThrow(RuntimeException::class, 'once it has ended');

    $this->locks->finalise($hr, $company->id, '2026-08');

    expect(fn () => $this->locks->reopen($viewer, $company->id, '2026-08', 'Payroll found a mistake'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->locks->reopen($hr, $company->id, '2026-08', 'oops'))->toThrow(InvalidArgumentException::class, 'at least 10 characters');

    $lock = $this->locks->reopen($hr, $company->id, '2026-08', 'Payroll found a missing day for Bilal');

    expect($lock->status)->toBe(AttendanceMonthLock::Reopened)
        ->and($lock->reopen_reason)->toBe('Payroll found a missing day for Bilal')
        ->and(AttendanceMonthLockEvent::query()->where('lock_id', $lock->id)->orderBy('id')->pluck('action')->all())->toBe(['finalised', 'reopened'])
        ->and(AttendanceMonthLockEvent::query()->where('lock_id', $lock->id)->where('action', 'reopened')->value('reason'))->toBe('Payroll found a missing day for Bilal')
        ->and($this->report->forCompany($hr, $company->id, '2026-08')['finalised'])->toBeNull();

    // Writable again; the next finalise replaces the snapshot.
    solPunch($team['employee'], '2026-08-04', '10:00', '18:00');
    $this->locks->finalise($hr, $company->id, '2026-08');

    $snapshot = AttendanceMonthSnapshot::query()->where('company_id', $company->id)->where('month', '2026-08')->where('employee_id', $team['employee']->id)->get();
    expect($snapshot)->toHaveCount(1)
        ->and($snapshot->first()->data['summary']['present_days'])->toBe(2)
        ->and(AttendanceMonthLockEvent::query()->where('lock_id', $lock->id)->count())->toBe(3);
});

it('finalises and re-opens from the monthly attendance page', function () {
    $company = solCompany('Truck It In Page Test');
    $team = solTeam($company);
    solPunch($team['employee'], '2026-08-03', '10:00', '18:00');

    Auth::login($team['hr']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(AttendanceReport::class)
        ->set('filters.company_id', $company->id)
        ->set('filters.month', '2026-08')
        ->assertActionVisible('finaliseMonth')
        ->assertActionHidden('reopenMonth')
        ->callAction('finaliseMonth')
        ->assertNotified('Month finalised')
        ->assertSee('Finalised on')
        ->assertActionVisible('reopenMonth')
        ->callAction('reopenMonth', ['reason' => 'Late correction from the warehouse'])
        ->assertNotified('Month re-opened');

    expect($this->locks->finalisedLock($company->id, '2026-08'))->toBeNull();
});

// ---------------------------------------------------------------------
// Company isolation
// ---------------------------------------------------------------------

it('keeps schedules, overtime and month locks inside their own company', function () {
    $shipLine = solCompany('ShipLine Isolation');
    $openPort = solCompany('OpenPort Isolation');
    $a = solTeam($shipLine);
    $b = solTeam($openPort);
    solPunch($a['employee'], '2026-08-03', '10:00', '20:00');
    solPunch($b['employee'], '2026-08-03', '10:00', '20:00');

    $this->locks->finalise($a['hr'], $shipLine->id, '2026-08');

    // The other company's August is still open.
    expect(solPunch($b['employee'], '2026-08-04', '10:00', '18:00')->exists)->toBeTrue()
        ->and($this->locks->isLocked($openPort->id, '2026-08-04'))->toBeFalse()
        ->and(app(OvertimeService::class)->request($b['employee'], $b['employee_user'], '2026-08-03', 120)->status)->toBe('pending_approval');

    // HR of one company cannot finalise, assign schedules or see overtime of the other.
    expect(fn () => $this->locks->finalise($a['hr'], $openPort->id, '2026-08'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WorkScheduleService::class)->assign($a['hr'], $b['employee'], [
            'start_time' => '09:00', 'end_time' => '17:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5], 'effective_from' => '2026-09-01',
        ]))->toThrow(AuthorizationException::class)
        ->and(fn () => solSchedule($b['employee'], ['company_id' => $shipLine->id, 'effective_from' => '2026-09-01']))->toThrow(ValidationException::class)
        ->and(OvertimeService::minutesByDay($shipLine->id, [$a['employee']->id, $b['employee']->id], '2026-08-01', '2026-08-31')['pending'])->toBe([])
        ->and(OvertimeService::minutesByDay($openPort->id, [$a['employee']->id, $b['employee']->id], '2026-08-01', '2026-08-31')['pending'])->toBe([$b['employee']->id => ['2026-08-03' => 120]])
        ->and($this->locks->snapshotsFor($shipLine->id, '2026-08', [$b['employee']->id]))->toBe([])
        ->and(fn () => $this->report->forCompany($a['hr'], $openPort->id, '2026-08'))->toThrow(AuthorizationException::class);

    // The overtime approver of one company is never asked to decide the other's.
    $approval = EmployeeRequest::query()->where('employee_id', $b['employee']->id)->first()->approvalRequest;
    expect(app(ApprovalEngine::class)->canAct($approval, $a['hr']))->toBeFalse()
        ->and(app(ApprovalEngine::class)->canAct($approval, $a['manager_user']))->toBeFalse();
});
