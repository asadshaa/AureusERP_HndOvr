<?php

/**
 * HR's monthly attendance report (Phase 1.5 of docs/HR_PARITY_PLAN.md): the
 * Google Sheet template's arithmetic, leave / OD / holiday handling, company
 * isolation, permissions, and the Excel workbook.
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\AttendanceReport;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceExcel;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    // August 2026 is complete; Karachi is UTC+5.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 06:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'               => '10:00',
        'attendance.monthly_report.office_end'                 => '18:00',
        'attendance.monthly_report.daily_required_minutes'     => 480,
        'attendance.monthly_report.max_outside_credit_minutes' => 60,
        'attendance.monthly_report.timezone'                   => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'               => [6, 7],
        'attendance.monthly_report.show_off_days'              => false,
        'attendance.monthly_report.count_off_day_work'         => false,
    ]);
    $this->report = app(MonthlyAttendanceReport::class);
});

function marCompany(string $name = 'Truck It In Test'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

function marEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id'      => $company->id,
        'name'            => 'Ayesha Khan',
        'employee_number' => null,
        'job_title'       => 'Dispatcher',
        'is_active'       => true,
        'time_zone'       => 'Asia/Karachi',
    ]);
}

function marUser(Company $company, array $permissions, string $role): User
{
    $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($roleModel);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function marHr(Company $company): User
{
    return marUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'mar_hr_viewer');
}

/** Punches in Karachi local time ("H:i"); null = no punch. */
function marPunch(Employee $employee, string $date, ?string $in, ?string $out): AttendanceRecord
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

function marLeave(Employee $employee, string $typeName, string $from, string $to, array $extra = []): void
{
    $type = LeaveType::query()->firstOrCreate(['company_id' => $employee->company_id, 'name' => $typeName], ['is_active' => true]);

    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $type->id,
        'state'             => 'validate_two',
        'request_date_from' => $from.' 00:00:00',
        'request_date_to'   => $to.' 00:00:00',
        'number_of_days'    => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/** h:mm of a seconds figure, as the sheet shows it. */
function marHm(int $seconds): string
{
    return intdiv($seconds, 3600).':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
}

/** @return array<string, array<string, mixed>> rows keyed by date */
function marRows(array $employeeReport): array
{
    return collect($employeeReport['rows'])->keyBy('date')->all();
}

/** The template month: rows from the sheet, OD, half OD, leave, holiday, absence, missing check-out. */
function marTemplateMonth(Company $company): Employee
{
    $employee = marEmployee($company, ['employee_number' => 'TII-001']);

    marPunch($employee, '2026-08-03', '10:20', '17:34');
    marLeave($employee, 'On Duty', '2026-08-04', '2026-08-04');
    marPunch($employee, '2026-08-05', '11:29', '18:58');
    marPunch($employee, '2026-08-06', '10:03', '19:35');
    marLeave($employee, 'On Duty', '2026-08-07', '2026-08-07', ['request_unit_half' => 1, 'request_date_from_period' => 'morning']);
    marPunch($employee, '2026-08-07', '10:08', '18:00');
    marPunch($employee, '2026-08-08', '11:00', '13:00'); // Saturday work: shown, not required
    marLeave($employee, 'Casual Leave', '2026-08-10', '2026-08-10');
    marLeave($employee, 'Casual Leave', '2026-08-11', '2026-08-11', ['request_unit_half' => 1, 'request_date_from_period' => 'afternoon']);
    marPunch($employee, '2026-08-13', '10:00', null);
    CalendarLeave::query()->create(['name' => 'Independence Day', 'time_type' => 'leave', 'date_from' => '2026-08-14', 'date_to' => '2026-08-14', 'company_id' => $company->id]);
    marPunch($employee, '2026-08-17', '11:49', '20:10');

    return $employee;
}

it('reproduces the template rows exactly', function () {
    $company = marCompany();
    $rows = marRows($this->report->forEmployee(marTemplateMonth($company), $company->id, '2026-08'));

    // 3-Aug: 10:20 - 17:34
    expect($rows['2026-08-03']['before_minutes'])->toBe(0)
        ->and($rows['2026-08-03']['after_minutes'])->toBe(0)
        ->and(marHm($rows['2026-08-03']['inside_seconds']))->toBe('7:14')
        ->and($rows['2026-08-03']['credit_minutes'])->toBe(0)
        ->and(marHm($rows['2026-08-03']['final_seconds']))->toBe('7:14')
        ->and($rows['2026-08-03']['late'])->toBeTrue();

    // 5-Aug: 11:29 - 18:58
    expect(marHm($rows['2026-08-05']['inside_seconds']))->toBe('6:31')
        ->and($rows['2026-08-05']['after_minutes'])->toBe(58)
        ->and($rows['2026-08-05']['credit_minutes'])->toBe(58)
        ->and(marHm($rows['2026-08-05']['final_seconds']))->toBe('7:29');

    // 6-Aug: 10:03 - 19:35 (95 outside, capped at 60, final not capped at 8:00)
    expect(marHm($rows['2026-08-06']['inside_seconds']))->toBe('7:57')
        ->and($rows['2026-08-06']['after_minutes'])->toBe(95)
        ->and($rows['2026-08-06']['credit_minutes'])->toBe(60)
        ->and(marHm($rows['2026-08-06']['final_seconds']))->toBe('8:57');

    // 17-Aug: 11:49 - 20:10
    expect(marHm($rows['2026-08-17']['inside_seconds']))->toBe('6:11')
        ->and($rows['2026-08-17']['credit_minutes'])->toBe(60)
        ->and(marHm($rows['2026-08-17']['final_seconds']))->toBe('7:11');

    // On-Duty, no punches: the whole office day.
    expect($rows['2026-08-04']['remarks'])->toBe('On-Duty')
        ->and(marHm($rows['2026-08-04']['final_seconds']))->toBe('8:00')
        ->and($rows['2026-08-04']['absent'])->toBeFalse()
        ->and($rows['2026-08-04']['clamped_in'])->toBe(10 * 3600)
        ->and($rows['2026-08-04']['clamped_out'])->toBe(18 * 3600);

    // Half OD first half, actual 10:08 - 18:00: OD 10-14 merged with the punches.
    expect($rows['2026-08-07']['remarks'])->toBe('Half OD - first half')
        ->and(marHm($rows['2026-08-07']['final_seconds']))->toBe('8:00')
        ->and($rows['2026-08-07']['late'])->toBeFalse()
        ->and($rows['2026-08-07']['od_days'])->toBe(0.5);
});

it('leaves weekends out of the rows and the required hours', function () {
    $company = marCompany();
    $employee = marEmployee($company);
    marPunch($employee, '2026-08-03', '10:00', '18:00');

    $result = $this->report->forEmployee($employee, $company->id, '2026-08');
    $dates = array_column($result['rows'], 'date');

    expect($dates)->not->toContain('2026-08-08')
        ->and($dates)->not->toContain('2026-08-09')
        ->and($dates)->not->toContain('2026-08-15')
        ->and($dates)->not->toContain('2026-08-16')
        ->and($result['summary']['working_days'])->toBe(21)
        ->and($result['summary']['required_hours'])->toBe(168.0);
});

it('excludes public holidays and approved leave from required hours, and handles absences and missing check-outs', function () {
    $company = marCompany();
    $result = $this->report->forEmployee(marTemplateMonth($company), $company->id, '2026-08');
    $rows = marRows($result);
    $summary = $result['summary'];

    expect($rows['2026-08-14']['type'])->toBe('holiday')
        ->and($rows['2026-08-14']['remarks'])->toBe('Public holiday: Independence Day')
        ->and($rows['2026-08-14']['required_seconds'])->toBe(0)
        ->and($rows['2026-08-10']['type'])->toBe('leave')
        ->and($rows['2026-08-10']['remarks'])->toBe('Casual Leave')
        ->and($rows['2026-08-10']['required_seconds'])->toBe(0)
        ->and($rows['2026-08-11']['required_seconds'])->toBe(4 * 3600)
        ->and($rows['2026-08-11']['remarks'])->toContain('Casual Leave - second half');

    // Absent: a working day with no punches.
    expect($rows['2026-08-12']['absent'])->toBeTrue()
        ->and($rows['2026-08-12']['remarks'])->toBe('Absent')
        ->and($rows['2026-08-12']['final_seconds'])->toBe(0)
        ->and($rows['2026-08-12']['required_seconds'])->toBe(8 * 3600);

    // Missing check-out: counted as 0 and flagged for HR.
    expect($rows['2026-08-13']['missing_check_out'])->toBeTrue()
        ->and($rows['2026-08-13']['remarks'])->toContain('Missing check-out')
        ->and($rows['2026-08-13']['final_seconds'])->toBe(0);

    // Saturday work is shown but neither required nor counted.
    expect($rows['2026-08-08']['type'])->toBe('weekend')
        ->and($rows['2026-08-08']['off_day_work'])->toBeTrue();

    // 21 weekdays - holiday - full leave = 19 working days, one of them half leave.
    expect($summary['working_days'])->toBe(19)
        ->and($summary['required_hours'])->toBe(148.0)
        ->and($summary['worked_hours'])->toBe(46.85) // 7:14 + 8:00 + 7:29 + 8:57 + 8:00 + 7:11
        ->and($summary['delta_hours'])->toBe(101.15)
        ->and($summary['penalty_percent'])->toBe(68.34)
        ->and($summary['present_days'])->toBe(7)
        ->and($summary['absent_days'])->toBe(12)
        ->and($summary['leave_days'])->toBe(1.5)
        ->and($summary['od_days'])->toBe(1.5)
        ->and($summary['holidays'])->toBe(1)
        ->and($summary['late_days'])->toBe(4)
        ->and($summary['missing_check_outs'])->toBe(1)
        ->and($summary['off_day_work_hours'])->toBe(2.0);
});

it('computes delta and penalty like the template (152 required, 150.47 worked)', function () {
    $rows = [
        ['working' => true, 'type' => 'working', 'final_seconds' => (int) round(150.47 * 3600), 'required_seconds' => 152 * 3600, 'present' => true, 'absent' => false, 'late' => false, 'late_minutes' => 0, 'missing_check_out' => false, 'od_days' => 0, 'leave_days' => 0, 'off_day_seconds' => 0],
    ];

    $summary = $this->report->summarize($rows);

    expect($summary['worked_hours'])->toBe(150.47)
        ->and($summary['required_hours'])->toBe(152.0)
        ->and($summary['delta_hours'])->toBe(1.53)
        ->and($summary['penalty_percent'])->toBe(1.01);
});

it('keeps companies apart', function () {
    $companyA = marCompany('Company A');
    $companyB = marCompany('Company B');
    $employeeA = marEmployee($companyA, ['name' => 'Alpha']);
    $employeeB = marEmployee($companyB, ['name' => 'Bravo', 'employee_number' => 'B-1']);
    marPunch($employeeB, '2026-08-03', '10:00', '18:00');

    // Another company's employee is refused.
    expect(fn () => $this->report->forEmployee($employeeB, $companyA->id, '2026-08'))->toThrow(AuthorizationException::class);

    // A punch stored under another company never counts.
    AttendanceRecord::query()->create([
        'company_id' => $companyB->id, 'employee_id' => $employeeA->id, 'attendance_date' => '2026-08-04',
        'check_in'   => '2026-08-04 05:00:00', 'check_out' => '2026-08-04 13:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $rows = marRows($this->report->forEmployee($employeeA, $companyA->id, '2026-08'));
    expect($rows['2026-08-04']['absent'])->toBeTrue();

    // HR of company A sees A only, and cannot ask for B.
    $hr = marHr($companyA);
    $report = $this->report->forCompany($hr, $companyA->id, '2026-08');
    expect(collect($report['employees'])->pluck('employee.name')->all())->toContain('Alpha')->not->toContain('Bravo');
    expect(fn () => $this->report->forCompany($hr, $companyB->id, '2026-08'))->toThrow(AuthorizationException::class);
});

it('requires attendance permission and limits line managers to their own people', function () {
    $company = marCompany();
    $managerUser = marUser($company, [HrPermissions::ManageAttendance], 'mar_line_manager');
    $plain = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $plain->allowedCompanies()->syncWithoutDetaching([$company->id]);

    expect(fn () => $this->report->forCompany($plain, $company->id, '2026-08'))->toThrow(AuthorizationException::class)
        ->and(MonthlyAttendanceReport::canView($plain))->toBeFalse();

    $manager = marEmployee($company, ['name' => 'Manager', 'user_id' => $managerUser->id]);
    marEmployee($company, ['name' => 'Direct Report', 'parent_id' => $manager->id]);
    marEmployee($company, ['name' => 'Somebody Else']);

    $names = collect($this->report->forCompany($managerUser, $company->id, '2026-08')['employees'])->pluck('employee.name');

    expect($names->all())->toContain('Manager', 'Direct Report')->not->toContain('Somebody Else');
});

it('filters by department and employee', function () {
    $company = marCompany();
    $first = marEmployee($company, ['name' => 'First']);
    marEmployee($company, ['name' => 'Second']);

    $report = $this->report->forCompany(marHr($company), $company->id, '2026-08', null, [$first->id]);

    expect(collect($report['employees'])->pluck('employee.name')->all())->toBe(['First']);
});

it('writes the template workbook with a summary and one sheet per employee', function () {
    $company = marCompany('Truck It In Report Test (Pvt) Ltd');
    marTemplateMonth($company);
    marEmployee($company, ['name' => 'Ayesha Khan', 'employee_number' => 'TII-002']); // same name: unique sheet title

    $report = $this->report->forCompany(marHr($company), $company->id, '2026-08');
    $excel = app(MonthlyAttendanceExcel::class);

    expect($excel->fileName($report))->toBe('Attendance-Truck-It-In-Report-Test-Pvt-Ltd-2026-08.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'mar').'.xlsx';
    (new Xlsx($excel->build($report)))->save($path);
    $book = IOFactory::load($path);
    @unlink($path);

    expect($book->getSheetNames())->toBe(['Summary', 'Ayesha Khan', 'Ayesha Khan (2)']);

    $sheet = $book->getSheetByName('Ayesha Khan');
    $header = MonthlyAttendanceExcel::HEADER_ROW;
    $headers = $sheet->rangeToArray('A'.$header.':O'.$header)[0];
    expect($headers)->toBe([
        'Date', 'First In', 'Last Out', 'Remarks', 'Checkin with 10 or after', 'Checkout with 6 or before',
        'starting time before 10 AM (mins)', 'Ending time after 6 PM (mins)', 'Total hours b/w 10-6',
        '1 hr/60 min outside 10-6', 'Final working hours (max 1 hour additional given outside 10-6)',
        'Total Hrs Worked', 'Total Hrs Req', 'Delta', 'Penalty',
    ]);

    $first = $header + 1; // 3-Aug-2026
    expect($sheet->getCell('A'.$first)->getFormattedValue())->toBe('3-Aug-2026')
        ->and($sheet->getCell('B'.$first)->getFormattedValue())->toBe('10:20:00 AM')
        ->and($sheet->getCell('C'.$first)->getFormattedValue())->toBe('5:34:00 PM')
        ->and($sheet->getCell('I'.$first)->getFormattedValue())->toBe('7:14')
        ->and($sheet->getCell('K'.$first)->getFormattedValue())->toBe('7:14')
        ->and((float) $sheet->getCell('L'.$first)->getValue())->toBe(46.85)
        ->and((float) $sheet->getCell('M'.$first)->getValue())->toBe(148.0)
        ->and((float) $sheet->getCell('N'.$first)->getValue())->toBe(101.15)
        ->and($sheet->getCell('O'.$first)->getFormattedValue())->toBe('68.34%')
        ->and($sheet->getStyle('A1')->getFont()->getName())->toBe('Times New Roman')
        ->and($sheet->getFreezePane())->toBe('A'.$first)
        ->and($sheet->getAutoFilter()->getRange())->not->toBe('');

    $sixth = collect(range($first, $first + 10))->first(fn (int $row): bool => $sheet->getCell('A'.$row)->getFormattedValue() === '6-Aug-2026');
    expect($sheet->getCell('J'.$sixth)->getValue())->toBe(60)
        ->and($sheet->getCell('K'.$sixth)->getFormattedValue())->toBe('8:57');

    $summary = $book->getSheetByName('Summary');
    $summaryHeader = MonthlyAttendanceExcel::SUMMARY_HEADER_ROW;
    expect($summary->getCell('B2')->getValue())->toBe('Truck It In Report Test (Pvt) Ltd')
        ->and($summary->getCell('B3')->getValue())->toBe('August 2026')
        ->and($summary->getCell('A'.$summaryHeader)->getValue())->toBe('Code')
        ->and($summary->getCell('A'.($summaryHeader + 1))->getValue())->toBe('TII-001')
        ->and((float) $summary->getCell('M'.($summaryHeader + 1))->getValue())->toBe(46.85);
});

it('shows the page to HR and streams the Excel download', function () {
    $company = marCompany('ShipLine Test');
    marTemplateMonth($company);
    $hr = marHr($company);

    Auth::login($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(AttendanceReport::class)
        ->assertOk()
        ->set('filters.month', '2026-08')
        ->assertSee('Ayesha Khan')
        ->assertSee('68.34%')
        ->callAction('downloadExcel')
        ->assertFileDownloaded('Attendance-ShipLine-Test-2026-08.xlsx');

    $outsider = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);
    Auth::login($outsider);

    expect(AttendanceReport::canAccess())->toBeFalse();
});

it('counts punches to the minute, dropping seconds like the sheet', function () {
    $company = marCompany();
    $employee = marEmployee($company);
    marPunch($employee, '2026-08-03', '10:20:45', '17:34:10');

    $rows = marRows($this->report->forEmployee($employee, $company->id, '2026-08'));

    expect(marHm($rows['2026-08-03']['inside_seconds']))->toBe('7:14')
        ->and(marHm($rows['2026-08-03']['final_seconds']))->toBe('7:14')
        ->and($rows['2026-08-03']['late'])->toBeTrue();
});
