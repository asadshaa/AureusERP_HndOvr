<?php

/**
 * Attendance -> Today's attendance (Phase 1.2 of docs/HR_PARITY_PLAN.md):
 * status classification, approved-leave-only, holidays / weekends, missing
 * check-out, company isolation, line-manager limits, flat query count, the
 * fixed dashboard roll call and the 11:00 HR digest.
 */

use App\Filament\Widgets\TodayAttendanceRollCallWidget;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\TodayAttendance as TodayAttendancePage;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\AttendanceIntervalService;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    // Wednesday 28 Oct 2026, 12:00 in Karachi (UTC+5).
    $this->travelTo(CarbonImmutable::parse('2026-10-28 07:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'       => '10:00',
        'attendance.monthly_report.office_end'         => '18:00',
        'attendance.monthly_report.late_grace_minutes' => 10,
        'attendance.monthly_report.timezone'           => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'       => [6, 7],
    ]);
    $this->service = app(TodayAttendance::class);
});

function tatCompany(string $name = 'Today Attendance Co'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

function tatEmployee(Company $company, string $name, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

function tatUser(Company $company, array $permissions, string $role): User
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

function tatHr(Company $company): User
{
    return tatUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'tat_hr_viewer');
}

/** Punch in Karachi local time ("H:i"); null = no punch. */
function tatPunch(Employee $employee, string $date, ?string $in, ?string $out, array $extra = []): AttendanceRecord
{
    $utc = fn (?string $time): ?string => $time === null ? null : CarbonImmutable::parse($date.' '.$time, 'Asia/Karachi')->setTimezone('UTC')->toDateTimeString();

    return AttendanceRecord::query()->create($extra + [
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => $date,
        'check_in'        => $utc($in),
        'check_out'       => $utc($out),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
}

function tatLeave(Employee $employee, string $typeName, string $from, string $to, string $state = 'validate_two', array $extra = []): void
{
    $type = LeaveType::query()->firstOrCreate(['company_id' => $employee->company_id, 'name' => $typeName], ['is_active' => true]);

    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $type->id,
        'state'             => $state,
        'request_date_from' => $from.' 00:00:00',
        'request_date_to'   => $to.' 00:00:00',
        'number_of_days'    => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

function tatRow(array $report, Employee $employee): array
{
    return $report['rows'][$employee->id];
}

it('classifies present, late with grace, absent, approved vs pending leave, On-Duty and half day', function () {
    $company = tatCompany();
    $onTime = tatEmployee($company, 'On Time');
    $inGrace = tatEmployee($company, 'In Grace');
    $late = tatEmployee($company, 'Late Comer');
    $absent = tatEmployee($company, 'No Show');
    $onLeave = tatEmployee($company, 'Annual Leaver');
    $pending = tatEmployee($company, 'Pending Leaver');
    $onDuty = tatEmployee($company, 'Field Visit');
    $halfDay = tatEmployee($company, 'Half Day');

    tatPunch($onTime, '2026-10-28', '09:55', null, ['source' => 'gps', 'verification_status' => 'verified']);
    tatPunch($inGrace, '2026-10-28', '10:10', null);
    tatPunch($late, '2026-10-28', '10:25', null, ['source' => 'self_service', 'verification_status' => 'remote']);
    tatLeave($onLeave, 'Annual', '2026-10-27', '2026-10-29');
    tatLeave($pending, 'Casual', '2026-10-28', '2026-10-28', 'confirm');
    tatLeave($onDuty, 'On Duty', '2026-10-28', '2026-10-28');
    tatLeave($halfDay, 'Sick', '2026-10-28', '2026-10-28', 'validate_two', ['request_unit_half' => 1, 'request_date_from_period' => 'afternoon']);
    tatPunch($halfDay, '2026-10-28', '09:58', null);

    $report = $this->service->forCompany(tatHr($company), $company->id);

    expect(tatRow($report, $onTime))->toMatchArray(['status' => 'present', 'status_label' => 'Present', 'first_in' => '09:55', 'worked' => '2:05', 'source' => 'gps', 'missing_check_out' => false])
        ->and(tatRow($report, $inGrace))->toMatchArray(['status' => 'present', 'late' => false])
        ->and(tatRow($report, $late))->toMatchArray(['status' => 'late', 'status_label' => 'Late 25m', 'late_minutes' => 25, 'remote' => true])
        ->and(tatRow($report, $absent))->toMatchArray(['status' => 'absent', 'status_label' => 'Absent'])
        ->and(tatRow($report, $onLeave))->toMatchArray(['status' => 'on_leave', 'status_label' => 'On leave: Annual'])
        ->and(tatRow($report, $pending))->toMatchArray(['status' => 'absent', 'on_leave' => false])
        ->and(tatRow($report, $onDuty))->toMatchArray(['status' => 'on_duty', 'status_label' => 'On-Duty', 'absent' => false])
        ->and(tatRow($report, $halfDay))->toMatchArray(['status' => 'half_day', 'status_label' => 'Half day: Sick'])
        ->and($report['off_day'])->toBeNull()
        ->and($report['counts'])->toMatchArray([
            'total'             => 8,
            'present'           => 4,
            'late'              => 1,
            'absent'            => 2,
            'on_leave'          => 2,
            'on_duty'           => 1,
            'remote'            => 1,
            'gps_office'        => 1,
            'missing_check_out' => 0,
        ]);

    // Problems first, then by name.
    expect(array_column(TodayAttendance::sortProblemsFirst($report['rows']), 'employee'))
        ->toBe(['No Show', 'Pending Leaver', 'Late Comer', 'Half Day', 'In Grace', 'On Time', 'Field Visit', 'Annual Leaver']);
});

it('says "not checked in yet" before the late threshold and still counts it as absent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 09:30:00', 'Asia/Karachi'));
    $company = tatCompany();
    $employee = tatEmployee($company, 'Early Bird Missing');

    $report = $this->service->forCompany(tatHr($company), $company->id);

    expect(tatRow($report, $employee))->toMatchArray(['status' => 'absent', 'status_label' => 'Not checked in yet'])
        ->and($report['counts']['absent'])->toBe(1);
});

it('flags a missing check-out only once the day is over or office hours have ended', function () {
    $company = tatCompany();
    $employee = tatEmployee($company, 'Forgetful');
    $hr = tatHr($company);
    tatPunch($employee, '2026-10-27', '09:50', null);
    tatPunch($employee, '2026-10-28', '09:50', null);

    // Yesterday: the day is over.
    $yesterday = $this->service->forCompany($hr, $company->id, '2026-10-27');
    expect(tatRow($yesterday, $employee))->toMatchArray(['status' => 'missing_check_out', 'missing_check_out' => true])
        ->and($yesterday['counts']['missing_check_out'])->toBe(1);

    // Today at 12:00: still at work.
    expect(tatRow($this->service->forCompany($hr, $company->id), $employee))->toMatchArray(['status' => 'present', 'missing_check_out' => false]);

    // Today after office end.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 19:00:00', 'Asia/Karachi'));
    expect(tatRow(app(TodayAttendance::class)->forCompany($hr, $company->id), $employee))->toMatchArray(['status' => 'missing_check_out']);
});

it('shows public holidays and weekends as off days, never absent', function () {
    $company = tatCompany();
    $employee = tatEmployee($company, 'Off Day');
    $worker = tatEmployee($company, 'Weekend Worker');
    $hr = tatHr($company);
    CalendarLeave::query()->create(['name' => 'Iqbal Day', 'time_type' => 'leave', 'date_from' => '2026-10-26', 'date_to' => '2026-10-26', 'company_id' => $company->id]);
    tatPunch($worker, '2026-10-24', '11:00', '14:00');

    $holiday = $this->service->forCompany($hr, $company->id, '2026-10-26');
    expect($holiday['off_day'])->toBe(['type' => 'holiday', 'name' => 'Iqbal Day'])
        ->and(tatRow($holiday, $employee))->toMatchArray(['status' => 'holiday', 'status_label' => 'Holiday: Iqbal Day'])
        ->and($holiday['counts']['absent'])->toBe(0);

    $weekend = $this->service->forCompany($hr, $company->id, '2026-10-24');
    expect($weekend['off_day'])->toBe(['type' => 'weekend', 'name' => 'Saturday'])
        ->and(tatRow($weekend, $employee)['status'])->toBe('weekend')
        ->and(tatRow($weekend, $worker))->toMatchArray(['status' => 'weekend', 'status_label' => 'Weekend (worked)', 'worked' => '3:00'])
        ->and($weekend['counts']['absent'])->toBe(0);
});

it('keeps companies apart', function () {
    $mine = tatCompany('Mine Co');
    $other = tatCompany('Other Co');
    $employee = tatEmployee($mine, 'Mine Person');
    $foreign = tatEmployee($other, 'Other Person');
    tatPunch($foreign, '2026-10-28', '09:00', null);
    $hr = tatHr($mine);

    $report = $this->service->forCompany($hr, $mine->id);

    expect(array_keys($report['rows']))->toBe([$employee->id])
        ->and($report['counts']['present'])->toBe(0);

    expect(fn () => $this->service->forCompany($hr, $other->id))->toThrow(AuthorizationException::class);

    // The page refuses a company the user does not belong to, even when asked for it.
    Auth::login($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(TodayAttendancePage::class)
        ->set('filters.company_id', $other->id)
        ->assertDontSee('Other Person');
});

it('limits a line manager to their own people', function () {
    $company = tatCompany();
    $managerUser = tatUser($company, [HrPermissions::ManageAttendance], 'tat_line_manager');
    $manager = tatEmployee($company, 'The Manager', ['user_id' => $managerUser->id]);
    $report = tatEmployee($company, 'Direct Report', ['parent_id' => $manager->id]);
    $stranger = tatEmployee($company, 'Someone Else');

    $rows = $this->service->forCompany($managerUser, $company->id)['rows'];

    expect(array_keys($rows))->toEqualCanonicalizing([$manager->id, $report->id])
        ->and($rows)->not->toHaveKey($stranger->id);
});

it('runs a flat number of queries however many employees there are', function () {
    $company = tatCompany();
    $hr = tatHr($company);
    $manager = tatEmployee($company, 'Boss');

    $count = function () use ($hr, $company): int {
        app(TodayAttendance::class)->forCompany($hr, $company->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app()->forgetScopedInstances();
        app(TodayAttendance::class)->forCompany($hr, $company->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        $employee = tatEmployee($company, "Small {$i}", ['parent_id' => $manager->id]);
        tatPunch($employee, '2026-10-28', '09:50', null);
        tatLeave($employee, 'Annual', '2026-10-29', '2026-10-29');
    }
    $small = $count();

    foreach (range(1, 20) as $i) {
        $employee = tatEmployee($company, "Large {$i}", ['parent_id' => $manager->id]);
        tatPunch($employee, '2026-10-28', '10:30', '18:00');
        tatLeave($employee, 'Annual', '2026-10-28', '2026-10-28');
    }
    $large = $count();

    // 12 + one query for the day's work schedules (HR parity plan 2.2).
    expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(13);
});

it('renders the page with the problems list and downloads the Excel', function () {
    $company = tatCompany('ShipLine Today');
    tatEmployee($company, 'Absent Person');
    tatPunch(tatEmployee($company, 'Late Person'), '2026-10-28', '10:40', null);
    $hr = tatHr($company);

    Auth::login($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(TodayAttendancePage::class)
        ->assertOk()
        ->assertSee("Today's attendance")
        ->assertSee('Absent Person')
        ->assertSee('Late 40m')
        ->set('tableFilters.status.values', ['late'])
        ->assertDontSee('Absent Person')
        ->assertSee('Late Person')
        ->callAction('downloadExcel')
        ->assertFileDownloaded('Attendance-shipline-today-2026-10-28.xlsx');

    $outsider = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);
    Auth::login($outsider);

    expect(TodayAttendancePage::canAccess())->toBeFalse();
});

it('counts only approved leave on the dashboard roll call and links to the page', function () {
    $company = tatCompany();
    $approved = tatEmployee($company, 'Approved Leaver');
    $pending = tatEmployee($company, 'Pending Leaver');
    tatEmployee($company, 'Present Person');
    tatLeave($approved, 'Annual', '2026-10-28', '2026-10-28');
    tatLeave($pending, 'Annual', '2026-10-28', '2026-10-28', 'confirm');
    tatPunch(Employee::query()->where('company_id', $company->id)->where('name', 'Present Person')->firstOrFail(), '2026-10-28', '09:45', null);
    $hr = tatUser($company, [HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords], 'tat_hr_manager');

    Auth::login($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(TodayAttendanceRollCallWidget::class)
        ->assertViewHas('totalEmployees', 3)
        ->assertViewHas('onLeaveCount', 1)
        ->assertViewHas('presentCount', 1)
        ->assertViewHas('notCheckedIn', 1)
        ->assertSee("View today's attendance", false)
        ->assertSee(TodayAttendancePage::getUrl(), false);
});

it('sends the HR digest once per company per day', function () {
    $company = tatCompany('Digest Co');
    $hr = tatHr($company);
    $lineManager = tatUser($company, [HrPermissions::ManageAttendance], 'tat_digest_line_manager');
    tatEmployee($company, 'Missing Today');
    tatPunch(tatEmployee($company, 'Late Today'), '2026-10-28', '10:45', null);
    tatPunch(tatEmployee($company, 'Forgot Yesterday'), '2026-10-27', '09:40', null);
    $other = tatCompany('Digest Other');
    $otherHr = tatHr($other);

    $this->artisan('hr:today-attendance-digest', ['--company' => $company->id])->assertSuccessful();
    $this->artisan('hr:today-attendance-digest', ['--company' => $company->id])
        ->expectsOutputToContain('0 notification(s) sent')
        ->assertSuccessful();

    $notifications = $hr->notifications()->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['title'])->toBe("Today's attendance — Digest Co")
        // Forgot Yesterday has no check-in today either, so is absent today too.
        ->and($notifications->first()->data['body'])->toBe('Today: 2 absent, 1 late, 1 missing check-out from yesterday.')
        ->and($lineManager->notifications()->count())->toBe(0)
        ->and($otherHr->notifications()->count())->toBe(0);
});

it('uses the day\'s intervals: out at 12, back at 14:30 is present and worked is the sum', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00', 'Asia/Karachi'));
    $company = tatCompany();
    $employee = tatEmployee($company, 'Lunch Breaker');
    $record = tatPunch($employee, '2026-10-28', '10:00', '12:00');
    AttendanceIntervalService::quietly(function () use ($record): void {
        $intervals = app(AttendanceIntervalService::class);
        $intervals->open($record, CarbonImmutable::parse('2026-10-28 14:30:00', 'Asia/Karachi')->setTimezone('UTC'), 'manual');
        $intervals->syncSummary($record);
    });

    $row = tatRow(app(TodayAttendance::class)->forCompany(tatHr($company), $company->id), $employee);

    expect($row)->toMatchArray([
        'status'            => 'present',
        'first_in'          => '10:00',
        'last_out'          => '12:00',
        'check_ins'         => '10:00–12:00, 14:30–…',
        'currently_in'      => true,
        'missing_check_out' => false,
        'worked'            => '2:30',
    ]);

    // Closed at 18:00: worked = 2:00 + 3:30, not first-in to last-out.
    $record->refresh()->update(['check_out' => CarbonImmutable::parse('2026-10-28 18:00:00', 'Asia/Karachi')->setTimezone('UTC')->toDateTimeString()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 19:00:00', 'Asia/Karachi'));
    $row = tatRow(app(TodayAttendance::class)->forCompany(tatHr($company), $company->id), $employee);

    expect($row)->toMatchArray(['status' => 'present', 'last_out' => '18:00', 'worked' => '5:30', 'currently_in' => false, 'away_minutes' => 150]);
});

it('shows an approved short leave as its own status', function () {
    $company = tatCompany();
    $employee = tatEmployee($company, 'Dentist Visit');
    tatLeave($employee, 'Short leave', '2026-10-28', '2026-10-28', 'validate_two', ['request_unit_hours' => 1, 'request_hour_from' => 12, 'request_hour_to' => 14.5]);
    tatPunch($employee, '2026-10-28', '09:58', null);

    $report = $this->service->forCompany(tatHr($company), $company->id);

    expect(tatRow($report, $employee))->toMatchArray(['status' => 'short_leave', 'status_label' => 'Short leave 12:00–14:30', 'short_leave' => true])
        ->and($report['counts']['short_leave'])->toBe(1);
});
