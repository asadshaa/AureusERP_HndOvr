<?php

/**
 * Business rules of 2026-10-28: several check-in / check-out pairs per day,
 * half day, short leave, the per-day calculation over intervals, the time
 * bank / grace balance (per-company policy) and the monthly report + Excel
 * using the same numbers.
 */

require_once __DIR__.'/Attendance/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\AttendancePolicySettings;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendancePolicy;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\AttendanceIntervalService;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceExcel;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Seeders\AttendanceLeaveTypeSeeder;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\ShortLeavePolicy;

beforeEach(function () {
    // August 2026 is complete; Karachi is UTC+5.
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
        'attendance.short_leave.max_hours'                     => 3,
        'attendance.short_leave.max_per_month'                 => 2,
        'attendance.time_bank.monthly_grace_minutes'           => 0,
        'attendance.time_bank.carry_over'                      => false,
    ]);
    $this->report = app(MonthlyAttendanceReport::class);
    $this->intervals = app(AttendanceIntervalService::class);
});

function aitCompany(string $name = 'Interval Test Co'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

function aitEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => 'Bilal Ahmed',
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

function aitUtc(string $date, string $time): CarbonImmutable
{
    return CarbonImmutable::parse($date.' '.$time, 'Asia/Karachi')->setTimezone('UTC');
}

/**
 * A day with several pairs, written the way check-in / check-out writes them.
 *
 * @param  array<int, array{0: string, 1: ?string}>  $pairs  Karachi "H:i"
 */
function aitDay(Employee $employee, string $date, array $pairs): AttendanceRecord
{
    [$firstIn, $firstOut] = array_shift($pairs);
    $record = AttendanceRecord::query()->create([
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => $date,
        'check_in'        => aitUtc($date, $firstIn)->toDateTimeString(),
        'check_out'       => $firstOut ? aitUtc($date, $firstOut)->toDateTimeString() : null,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $service = app(AttendanceIntervalService::class);
    foreach ($pairs as [$in, $out]) {
        AttendanceIntervalService::quietly(function () use ($service, $record, $date, $in, $out): void {
            $service->open($record, aitUtc($date, $in), 'manual');
            if ($out !== null) {
                $service->close($record, aitUtc($date, $out));
            }
            $service->syncSummary($record);
        });
    }

    return $record->refresh();
}

function aitLeave(Employee $employee, string $typeName, string $date, array $extra = []): void
{
    $type = LeaveType::query()->firstOrCreate(['company_id' => $employee->company_id, 'name' => $typeName], ['is_active' => true]);

    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $type->id,
        'state'             => 'validate_two',
        'request_date_from' => $date.' 00:00:00',
        'request_date_to'   => $date.' 00:00:00',
        'number_of_days'    => 0.5,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

function aitHalfDay(Employee $employee, string $date, string $period): void
{
    aitLeave($employee, 'Half day', $date, ['request_unit_half' => 1, 'request_date_from_period' => $period]);
}

function aitShortLeave(Employee $employee, string $date, float $from, float $to): void
{
    aitLeave($employee, 'Short leave', $date, ['request_unit_hours' => 1, 'request_hour_from' => $from, 'request_hour_to' => $to, 'number_of_hours' => $to - $from, 'number_of_days' => ($to - $from) / 8]);
}

function aitRow(MonthlyAttendanceReport $report, Employee $employee, string $date): array
{
    return collect($report->forEmployee($employee, (int) $employee->company_id, substr($date, 0, 7))['rows'])->keyBy('date')[$date];
}

function aitHm(int $seconds): string
{
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);

    return $sign.intdiv($seconds, 3600).':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
}

function aitUser(Company $company, array $permissions, string $role): User
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

// ---------------------------------------------------------------------
// The business table (office 10-6). 2026-08-03 is a Monday.
// ---------------------------------------------------------------------

it('first half day off (10-2): required 4h, punches 2:00-6:00 = 4h, ok', function () {
    $employee = aitEmployee(aitCompany());
    aitHalfDay($employee, '2026-08-03', 'morning');
    aitDay($employee, '2026-08-03', [['14:00', '18:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['required_seconds']))->toBe('4:00')
        ->and(aitHm($row['final_seconds']))->toBe('4:00')
        ->and($row['late_minutes'])->toBe(0)
        ->and($row['late'])->toBeFalse()
        ->and($row['gap_minutes'])->toBe(0)
        ->and($row['net_seconds'])->toBe(0)
        ->and($row['remarks'])->toContain('Half day - first half');
});

it('measures lateness from 14:00 after a first-half day off', function () {
    $employee = aitEmployee(aitCompany());
    aitHalfDay($employee, '2026-08-03', 'morning');
    aitDay($employee, '2026-08-03', [['14:15', '18:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect($row['late_minutes'])->toBe(15)->and($row['late'])->toBeTrue();
});

it('second half day off (2-6): required 4h, punches 10:00-2:00 = 4h, ok', function () {
    $employee = aitEmployee(aitCompany());
    aitHalfDay($employee, '2026-08-03', 'afternoon');
    aitDay($employee, '2026-08-03', [['10:00', '14:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['required_seconds']))->toBe('4:00')
        ->and(aitHm($row['final_seconds']))->toBe('4:00')
        ->and($row['left_early_minutes'])->toBe(0)
        ->and($row['net_seconds'])->toBe(0);
});

it('short leave 12:00-2:30: required 5:30, punches 10-12 and 2:30-6 = 5:30, ok', function () {
    $employee = aitEmployee(aitCompany());
    aitShortLeave($employee, '2026-08-03', 12.0, 14.5);
    aitDay($employee, '2026-08-03', [['10:00', '12:00'], ['14:30', '18:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['required_seconds']))->toBe('5:30')
        ->and(aitHm($row['final_seconds']))->toBe('5:30')
        ->and($row['gap_minutes'])->toBe(0)
        ->and($row['net_seconds'])->toBe(0)
        ->and($row['intervals_text'])->toBe('10:00–12:00, 14:30–18:00')
        ->and($row['remarks'])->toContain('Short leave 12:00–14:30');
});

it('late back from short leave: punches 10-12 and 3:10-6 = 4:50, 0:40 short', function () {
    $employee = aitEmployee(aitCompany());
    aitShortLeave($employee, '2026-08-03', 12.0, 14.5);
    aitDay($employee, '2026-08-03', [['10:00', '12:00'], ['15:10', '18:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['required_seconds']))->toBe('5:30')
        ->and(aitHm($row['final_seconds']))->toBe('4:50')
        ->and(aitHm($row['net_seconds']))->toBe('-0:40')
        ->and($row['gap_minutes'])->toBe(40)
        ->and($row['late_minutes'])->toBe(0);
});

it('left without asking: punches 10-12 and 3-6 = 5h of 8h, 3h short', function () {
    $employee = aitEmployee(aitCompany());
    aitDay($employee, '2026-08-03', [['10:00', '12:00'], ['15:00', '18:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['required_seconds']))->toBe('8:00')
        ->and(aitHm($row['final_seconds']))->toBe('5:00')
        ->and(aitHm($row['net_seconds']))->toBe('-3:00')
        ->and($row['gap_minutes'])->toBe(180)
        ->and($row['remarks'])->toContain('Away without approval 180 min');
});

it('second half off but stayed till 7: real work inside 10-6 counts (8h) plus 60 min credit after 6', function () {
    // Decision: approved-off time actually worked is real work inside 10-6 (it is not
    // subtracted); work after 6 is outside credit capped at 60. Final 9:00 vs 4:00 required,
    // +5:00 to the time bank, and the row says so, so HR can cancel the half day.
    $employee = aitEmployee(aitCompany());
    aitHalfDay($employee, '2026-08-03', 'afternoon');
    aitDay($employee, '2026-08-03', [['10:00', '19:00']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    expect(aitHm($row['inside_seconds']))->toBe('8:00')
        ->and($row['credit_minutes'])->toBe(60)
        ->and(aitHm($row['final_seconds']))->toBe('9:00')
        ->and(aitHm($row['required_seconds']))->toBe('4:00')
        ->and($row['extra_minutes'])->toBe(300)
        ->and($row['remarks'])->toContain('Worked during approved leave');
});

it('counts worked inside 10-6 as the sum of the intervals, never first-in to last-out', function () {
    $employee = aitEmployee(aitCompany());
    aitDay($employee, '2026-08-03', [['09:30', '11:00'], ['11:30', '18:20']]);

    $row = aitRow($this->report, $employee, '2026-08-03');

    // inside = 1:00 + 6:30; outside = 30 before + 20 after = 50 credit.
    expect(aitHm($row['inside_seconds']))->toBe('7:30')
        ->and($row['credit_minutes'])->toBe(50)
        ->and(aitHm($row['final_seconds']))->toBe('8:20')
        ->and($row['gap_minutes'])->toBe(30)
        ->and($row['extra_minutes'])->toBe(50);
});

// ---------------------------------------------------------------------
// Intervals: check-in again, summary, corrections, backfill
// ---------------------------------------------------------------------

it('lets an employee check in again after checking out, keeping the day summary in step', function () {
    $f = geoFixture();
    $service = app(GeofencedAttendanceService::class);

    $this->travelTo(aitUtc('2026-10-05', '10:00'));
    expect($service->checkIn($f['user'], geoEvidence(), geoRequest())->accepted)->toBeTrue();
    $this->travelTo(aitUtc('2026-10-05', '12:00'));
    expect($service->checkOut($f['user'], geoEvidence(), geoRequest())->accepted)->toBeTrue();
    $this->travelTo(aitUtc('2026-10-05', '14:30'));
    expect($service->checkIn($f['user'], geoEvidence(), geoRequest())->accepted)->toBeTrue();

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();
    expect($record->check_out)->toBeNull()
        ->and($service->todayState($f['user']))->toMatchArray(['state' => 'checked_in', 'interval_count' => 2]);

    $this->travelTo(aitUtc('2026-10-05', '18:00'));
    expect($service->checkOut($f['user'], geoEvidence(), geoRequest())->accepted)->toBeTrue();

    $record->refresh();
    $intervals = $this->intervals->intervalsFor($record);
    expect($intervals)->toHaveCount(2)
        ->and($record->check_in->equalTo(aitUtc('2026-10-05', '10:00')))->toBeTrue()
        ->and($record->check_out->equalTo(aitUtc('2026-10-05', '18:00')))->toBeTrue()
        ->and((float) $record->worked_hours)->toBe(5.5)
        ->and($intervals->every(fn (AttendanceInterval $interval): bool => $interval->start_verification_id !== null && $interval->end_verification_id !== null))->toBeTrue()
        ->and(AttendanceIntervalService::describe($intervals, 'Asia/Karachi'))->toBe('10:00–12:00, 14:30–18:00')
        ->and($service->todayState($f['user']))->toMatchArray(['state' => 'checked_out', 'worked' => '5h 30m', 'can_check_in_again' => true]);
});

it('replaces the day\'s intervals with one audited interval on a correction', function () {
    $employee = aitEmployee(aitCompany());
    $record = aitDay($employee, '2026-08-03', [['10:00', '12:00'], ['14:30', '18:00']]);
    expect((float) $record->worked_hours)->toBe(5.5);

    $record->update(['check_in' => aitUtc('2026-08-03', '09:45')->toDateTimeString(), 'check_out' => aitUtc('2026-08-03', '17:45')->toDateTimeString()]);

    $active = $this->intervals->intervalsFor($record->refresh());
    $voided = AttendanceInterval::query()->where('attendance_record_id', $record->id)->whereNotNull('voided_at')->get();

    expect($active)->toHaveCount(1)
        ->and(AttendanceIntervalService::describe($active, 'Asia/Karachi'))->toBe('09:45–17:45')
        ->and((float) $record->worked_hours)->toBe(8.0)
        ->and($voided)->toHaveCount(2)
        ->and($voided->first()->void_reason)->not->toBeEmpty();
});

it('closes the open interval when only the forgotten check-out is corrected', function () {
    $employee = aitEmployee(aitCompany());
    $record = aitDay($employee, '2026-08-03', [['10:00', '12:00'], ['14:30', null]]);
    expect($record->check_out)->toBeNull();

    $record->update(['check_out' => aitUtc('2026-08-03', '18:00')->toDateTimeString()]);

    $record->refresh();
    expect(AttendanceIntervalService::describe($this->intervals->intervalsFor($record), 'Asia/Karachi'))->toBe('10:00–12:00, 14:30–18:00')
        ->and((float) $record->worked_hours)->toBe(5.5);
});

it('backfills one interval per existing day (migration logic) without changing the day', function () {
    $employee = aitEmployee(aitCompany());
    $id = DB::table('employees_attendance_records')->insertGetId([
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => '2026-08-04',
        'check_in'        => aitUtc('2026-08-04', '10:05')->toDateTimeString(),
        'check_out'       => aitUtc('2026-08-04', '17:55')->toDateTimeString(),
        'worked_hours'    => 7.8333,
        'status'          => 'present',
        'source'          => 'gps',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    expect(AttendanceInterval::query()->where('attendance_record_id', $id)->count())->toBe(0);

    $migration = require base_path('plugins/webkul/employees/database/migrations/2026_10_28_100001_create_employees_attendance_intervals_table.php');
    $migration->up();
    $migration->up(); // idempotent

    $intervals = AttendanceInterval::query()->where('attendance_record_id', $id)->get();
    expect($intervals)->toHaveCount(1)
        ->and($intervals->first()->source)->toBe('system_backfill')
        ->and($intervals->first()->check_in->equalTo(aitUtc('2026-08-04', '10:05')))->toBeTrue()
        ->and($intervals->first()->check_out->equalTo(aitUtc('2026-08-04', '17:55')))->toBeTrue()
        ->and((float) DB::table('employees_attendance_records')->where('id', $id)->value('worked_hours'))->toBe(7.8333);
});

it('refuses an interval that belongs to another company than its day', function () {
    $employee = aitEmployee(aitCompany('A'));
    $other = aitCompany('B');
    $record = aitDay($employee, '2026-08-03', [['10:00', '18:00']]);

    expect(fn () => AttendanceInterval::query()->create([
        'company_id' => $other->id, 'employee_id' => $employee->id, 'attendance_record_id' => $record->id,
        'check_in'   => aitUtc('2026-08-03', '19:00'), 'source' => 'manual',
    ]))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// Short leave limits
// ---------------------------------------------------------------------

it('enforces the short leave limits (3 hours each, 2 per month) on the request itself', function () {
    $company = aitCompany();
    $employee = aitEmployee($company);
    $type = LeaveType::query()->firstOrCreate(['company_id' => $company->id, 'name' => 'Short leave'], ['is_active' => true, 'request_unit' => 'hour']);
    $make = fn (string $date, float $from, float $to) => Leave::query()->create([
        'employee_id'        => $employee->id,
        'company_id'         => $company->id,
        'holiday_status_id'  => $type->id,
        'request_unit_hours' => true,
        'request_hour_from'  => $from,
        'request_hour_to'    => $to,
        'request_date_from'  => $date,
        'request_date_to'    => $date,
        'number_of_days'     => ($to - $from) / 8,
        'state'              => 'confirm',
    ]);

    expect(fn () => $make('2026-09-07', 12.0, 15.5))->toThrow(ValidationException::class, 'at most 3 hours');

    $make('2026-09-07', 12.0, 14.5);
    $make('2026-09-09', 16.0, 18.0);

    expect(fn () => $make('2026-09-14', 11.0, 12.0))->toThrow(ValidationException::class, 'Only 2 short leaves are allowed per month');

    // A refused request frees its slot; the next month starts again.
    Leave::query()->where('employee_id', $employee->id)->orderBy('id')->first()->update(['state' => 'refuse']);
    expect($make('2026-09-14', 11.0, 12.0)->exists)->toBeTrue()
        ->and($make('2026-10-05', 11.0, 12.0)->exists)->toBeTrue();

    // The policy is the same rule the forms call.
    expect(fn () => app(ShortLeavePolicy::class)->validate(['employee_id' => $employee->id, 'request_unit_hours' => true, 'request_hour_from' => 10, 'request_hour_to' => 9, 'request_date_from' => '2026-11-02']))
        ->toThrow(ValidationException::class, 'must end after it starts');
});

// ---------------------------------------------------------------------
// Time bank / grace balance
// ---------------------------------------------------------------------

it('keeps a running grace ledger: allowance, late 15 min deducts 15, extra capped per day', function () {
    $company = aitCompany();
    AttendancePolicy::query()->create(['company_id' => $company->id, 'monthly_grace_minutes' => 60, 'daily_extra_credit_cap_minutes' => 60, 'carry_over' => false]);
    $employee = aitEmployee($company, ['joining_date' => '2026-08-03', 'departure_date' => '2026-08-05']);

    aitDay($employee, '2026-08-03', [['10:15', '18:00']]);          // late 15        → 60 - 15 = 45
    aitDay($employee, '2026-08-04', [['08:30', '18:30']]);          // 120 outside, capped 60 → 105
    aitDay($employee, '2026-08-05', [['10:00', '17:30']]);          // left early 30  → 75

    $hr = aitUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'ait_hr');
    $ledger = $this->report->graceLedger($hr, $employee, '2026-08');
    $rows = collect($ledger['rows'])->keyBy('date');

    expect($rows['2026-08-03'])->toMatchArray(['late_minutes' => 15, 'extra_minutes' => 0, 'balance_minutes' => 45])
        ->and($rows['2026-08-04'])->toMatchArray(['extra_minutes' => 60, 'late_minutes' => 0, 'balance_minutes' => 105])
        ->and($rows['2026-08-05'])->toMatchArray(['left_early_minutes' => 30, 'balance_minutes' => 75])
        ->and($ledger)->toMatchArray([
            'allowance_minutes'  => 60,
            'earned_minutes'     => 60,
            'used_minutes'       => 45,
            'balance_minutes'    => 75,
        ]);

    // The report's month totals are the same numbers; a positive balance means no delta.
    $summary = $this->report->forEmployee($employee, $company->id, '2026-08')['summary'];
    expect($summary)->toMatchArray([
        'late_minutes'            => 15,
        'left_early_minutes'      => 30,
        'time_bank_extra_minutes' => 60,
        'grace_allowance_minutes' => 60,
        'grace_balance_minutes'   => 75,
        'net_balance_minutes'     => 15,
        'delta_hours'             => 0.0,
    ]);
});

it('lets the balance go negative, and then -balance is the delta (shortfall)', function () {
    $company = aitCompany();
    AttendancePolicy::query()->create(['company_id' => $company->id, 'monthly_grace_minutes' => 30, 'daily_extra_credit_cap_minutes' => 60]);
    $employee = aitEmployee($company, ['joining_date' => '2026-08-03', 'departure_date' => '2026-08-03']);
    aitDay($employee, '2026-08-03', [['11:00', '18:00']]); // late 60, allowance 30 → -30

    $summary = $this->report->forEmployee($employee, $company->id, '2026-08')['summary'];

    expect($summary['grace_balance_minutes'])->toBe(-30)
        ->and($summary['delta_hours'])->toBe(0.5)
        ->and($summary['net_balance_minutes'])->toBe(-60);
});

it('time bank month net equals -delta when there is a shortfall and no allowance', function () {
    $employee = aitEmployee(aitCompany(), ['joining_date' => '2026-08-03', 'departure_date' => '2026-08-07']);
    aitDay($employee, '2026-08-03', [['10:20', '17:34']]);
    aitDay($employee, '2026-08-04', [['10:00', '12:00'], ['15:00', '18:00']]);
    aitDay($employee, '2026-08-05', [['09:00', '19:30']]);
    // 6 and 7 Aug absent.

    $summary = $this->report->forEmployee($employee, (int) $employee->company_id, '2026-08')['summary'];

    expect($summary['time_bank_extra_minutes'] - $summary['late_minutes'] - $summary['left_early_minutes'] - $summary['gap_minutes'])
        ->toBe($summary['net_balance_minutes'])
        ->and(round(-$summary['net_balance_minutes'] / 60, 2))->toBe($summary['delta_hours']);
});

it('resets the balance every month by default and carries unused earned extra over when enabled', function () {
    $company = aitCompany();
    $employee = aitEmployee($company, ['joining_date' => '2026-07-31']);
    aitDay($employee, '2026-07-31', [['09:00', '18:00']]); // Friday: +60 earned in July
    $hr = aitUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'ait_hr_carry');

    expect($this->report->graceLedger($hr, $employee, '2026-08')['carry_in_minutes'])->toBe(0);

    AttendancePolicy::query()->create(['company_id' => $company->id, 'monthly_grace_minutes' => 0, 'daily_extra_credit_cap_minutes' => 60, 'carry_over' => true]);

    $august = $this->report->graceLedger($hr, $employee, '2026-08');
    expect($august['carry_in_minutes'])->toBe(60)
        ->and($august['summary']['grace_carry_in_minutes'])->toBe(60);
});

it('keeps each company\'s policy separate and lets only that company\'s HR change it', function () {
    $companyA = aitCompany('Policy A');
    $companyB = aitCompany('Policy B');
    AttendancePolicy::query()->create(['company_id' => $companyA->id, 'monthly_grace_minutes' => 90, 'daily_extra_credit_cap_minutes' => 30, 'carry_over' => true]);

    expect($this->report->policyFor($companyA->id))->toBe(['monthly_grace_minutes' => 90, 'daily_extra_credit_cap_minutes' => 30, 'carry_over' => true])
        ->and($this->report->policyFor($companyB->id))->toBe(['monthly_grace_minutes' => 0, 'daily_extra_credit_cap_minutes' => 60, 'carry_over' => false]);

    // The cap is per company: 2h outside earns 30 min in A.
    $employee = aitEmployee($companyA, ['joining_date' => '2026-08-03', 'departure_date' => '2026-08-03']);
    aitDay($employee, '2026-08-03', [['08:00', '18:00']]);
    expect(aitRow($this->report, $employee, '2026-08-03')['credit_minutes'])->toBe(30);

    $hrA = aitUser($companyA, [HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords], 'ait_policy_hr');
    $lineManager = aitUser($companyA, [HrPermissions::ManageAttendance], 'ait_policy_line_manager');
    $hrB = aitUser($companyB, [HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords], 'ait_policy_hr');

    expect(AttendancePolicySettings::canManage($hrA, $companyA->id))->toBeTrue()
        ->and(AttendancePolicySettings::canManage($lineManager, $companyA->id))->toBeFalse()
        ->and(AttendancePolicySettings::canManage($hrB, $companyA->id))->toBeFalse();

    Auth::login($hrB);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Livewire::test(AttendancePolicySettings::class)
        ->set('data.monthly_grace_minutes', 45)
        ->set('data.daily_extra_credit_cap_minutes', 60)
        ->call('save');

    expect(AttendancePolicy::query()->where('company_id', $companyB->id)->value('monthly_grace_minutes'))->toBe(45)
        ->and(AttendancePolicy::query()->where('company_id', $companyA->id)->value('monthly_grace_minutes'))->toBe(90);
});

it('shows an employee only their own ledger; a line manager their team; HR the company', function () {
    $company = aitCompany();
    $managerUser = aitUser($company, [HrPermissions::ManageAttendance], 'ait_ledger_manager');
    $manager = aitEmployee($company, ['name' => 'Manager', 'user_id' => $managerUser->id]);
    $selfUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $selfUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $me = aitEmployee($company, ['name' => 'Me', 'user_id' => $selfUser->id, 'parent_id' => $manager->id]);
    $colleague = aitEmployee($company, ['name' => 'Colleague']);
    $outsiderHr = aitUser(aitCompany('Other'), [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'ait_ledger_hr');

    expect($this->report->graceLedger($selfUser, $me, '2026-08')['employee']['name'])->toBe('Me')
        ->and(fn () => $this->report->graceLedger($selfUser, $colleague, '2026-08'))->toThrow(AuthorizationException::class)
        ->and($this->report->canSeeLedger($managerUser, $me))->toBeTrue()
        ->and($this->report->canSeeLedger($managerUser, $colleague))->toBeFalse()
        ->and($this->report->canSeeLedger($outsiderHr, $me))->toBeFalse();
});

it('makes the report summary equal the ledger, and the Excel summary carries grace allowance and balance', function () {
    $company = aitCompany('Excel Grace Co');
    AttendancePolicy::query()->create(['company_id' => $company->id, 'monthly_grace_minutes' => 60, 'daily_extra_credit_cap_minutes' => 60]);
    $employee = aitEmployee($company, ['name' => 'Ali Raza', 'employee_number' => 'EGC-1', 'joining_date' => '2026-08-03', 'departure_date' => '2026-08-04']);
    aitDay($employee, '2026-08-03', [['10:15', '18:00']]);
    aitShortLeave($employee, '2026-08-04', 12.0, 14.5);
    aitDay($employee, '2026-08-04', [['10:00', '12:00'], ['15:10', '18:00']]);

    $hr = aitUser($company, [HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords], 'ait_excel_hr');
    $report = $this->report->forCompany($hr, $company->id, '2026-08');
    $ledger = $this->report->graceLedger($hr, $employee, '2026-08');
    $summary = $report['employees'][0]['summary'];

    expect($ledger['balance_minutes'])->toBe($summary['grace_balance_minutes'])
        ->and($ledger['balance_minutes'])->toBe(60 - 15 - 40)
        ->and(collect($ledger['rows'])->last()['balance_minutes'])->toBe($summary['grace_balance_minutes']);

    $path = tempnam(sys_get_temp_dir(), 'ait').'.xlsx';
    (new Xlsx(app(MonthlyAttendanceExcel::class)->build($report)))->save($path);
    $book = IOFactory::load($path);
    @unlink($path);

    $sheet = $book->getSheetByName('Summary');
    $header = MonthlyAttendanceExcel::SUMMARY_HEADER_ROW;
    $headers = $sheet->rangeToArray('A'.$header.':X'.$header)[0];
    $column = fn (string $name): string => Coordinate::stringFromColumnIndex(array_search($name, $headers, true) + 1);

    expect($headers)->toContain('Late (mins)', 'Left early (mins)', 'Extra (mins)', 'Net balance (mins)', 'Grace allowance (mins)', 'Grace balance (mins)')
        ->and((int) $sheet->getCell($column('Grace allowance (mins)').($header + 1))->getValue())->toBe(60)
        ->and((int) $sheet->getCell($column('Grace balance (mins)').($header + 1))->getValue())->toBe($summary['grace_balance_minutes'])
        ->and((int) $sheet->getCell($column('Late (mins)').($header + 1))->getValue())->toBe(15);

    $employeeSheet = $book->getSheetByName('Ali Raza');
    expect($employeeSheet->getCell('P1')->getValue())->toBe('Check-ins')
        ->and($employeeSheet->getCell('P3')->getValue())->toBe('10:00–12:00, 15:10–18:00')
        ->and($employeeSheet->getCell('U3')->getValue())->toBe($summary['grace_balance_minutes']);
});

it('lays the employee sheet out like the template: header on row 1, styles, totals on the first data row', function () {
    $rows = [[
        'date'           => '2026-08-03', 'type' => 'working', 'working' => true, 'first_in' => 10 * 3600 + 20 * 60, 'last_out' => 17 * 3600 + 34 * 60,
        'remarks'        => 'Late', 'clamped_in' => 10 * 3600 + 20 * 60, 'clamped_out' => 17 * 3600 + 34 * 60, 'before_minutes' => 0, 'after_minutes' => 0,
        'inside_seconds' => 7 * 3600 + 14 * 60, 'credit_minutes' => 0, 'final_seconds' => 7 * 3600 + 14 * 60, 'absent' => false, 'missing_check_out' => false,
        'late'           => true, 'late_minutes' => 20, 'left_early_minutes' => 26, 'extra_minutes' => 0, 'gap_minutes' => 0, 'off_day_work' => false,
        'intervals_text' => '10:20–17:34', 'grace_balance_seconds' => -46 * 60,
    ], [
        'date'               => '2026-08-04', 'type' => 'working', 'working' => true, 'first_in' => 10 * 3600, 'last_out' => 18 * 3600, 'remarks' => '',
        'clamped_in'         => 10 * 3600, 'clamped_out' => 18 * 3600, 'before_minutes' => 0, 'after_minutes' => 0, 'inside_seconds' => 8 * 3600,
        'credit_minutes'     => 0, 'final_seconds' => 8 * 3600, 'absent' => false, 'missing_check_out' => false, 'late' => false, 'late_minutes' => 0,
        'left_early_minutes' => 0, 'extra_minutes' => 0, 'gap_minutes' => 0, 'off_day_work' => false, 'intervals_text' => '10:00–18:00', 'grace_balance_seconds' => -46 * 60,
    ]];
    $report = [
        'company'      => ['id' => 1, 'name' => 'Truck It In'],
        'month'        => '2026-08',
        'month_label'  => 'August 2026',
        'generated_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Karachi'),
        'generated_by' => 'HR',
        'policy'       => ['monthly_grace_minutes' => 0, 'daily_extra_credit_cap_minutes' => 60, 'carry_over' => false],
        'employees'    => [[
            'employee' => ['id' => 1, 'code' => 'TII-001', 'name' => 'Ali Raza', 'department' => 'Operations', 'designation' => 'Driver'],
            'rows'     => $rows,
            'summary'  => ['worked_hours' => 150.47, 'required_hours' => 152.0, 'delta_hours' => 1.53, 'penalty_percent' => 1.01] + array_fill_keys([
                'working_days', 'present_days', 'absent_days', 'leave_days', 'od_days', 'late_days', 'missing_check_outs', 'holidays', 'extra_minutes', 'late_minutes',
                'left_early_minutes', 'time_bank_extra_minutes', 'gap_minutes', 'net_balance_minutes', 'grace_allowance_minutes', 'grace_carry_in_minutes', 'grace_balance_minutes',
            ], 0),
        ]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'ait').'.xlsx';
    (new Xlsx(app(MonthlyAttendanceExcel::class)->build($report)))->save($path);
    $book = IOFactory::load($path);
    @unlink($path);
    $sheet = $book->getSheetByName('Ali Raza');
    $headerStyle = $sheet->getStyle('A1');

    expect($sheet->getCell('A1')->getValue())->toBe('Date')
        ->and($sheet->rangeToArray('A1:O1')[0])->toBe([
            'Date', 'First In', 'Last Out', 'Remarks', 'Checkin with 10 or after', 'Checkout with 6 or before',
            'starting time before 10 AM (mins)', 'Ending time after 6 PM (mins)', 'Total hours b/w 10-6',
            '1 hr/60 min outside 10-6', 'Final working hours (max 1 hour additional given outside 10-6)',
            'Total Hrs Worked', 'Total Hrs Req', 'Delta', 'Penalty',
        ])
        ->and($sheet->rangeToArray('P1:U1')[0])->toBe(['Check-ins', 'Late (mins)', 'Left early (mins)', 'Extra (mins)', 'Away without approval (mins)', 'Grace balance (mins)'])
        ->and($headerStyle->getFont()->getBold())->toBeTrue()
        ->and($headerStyle->getFont()->getName())->toBe('Times New Roman')
        ->and($headerStyle->getAlignment()->getWrapText())->toBeTrue()
        ->and($headerStyle->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_CENTER)
        ->and($headerStyle->getAlignment()->getVertical())->toBe(Alignment::VERTICAL_BOTTOM)
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toStartWith('A1:')
        ->and($sheet->getCell('A2')->getFormattedValue())->toBe('3-Aug-2026')
        ->and($sheet->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe('d-mmm-yyyy')
        ->and($sheet->getCell('B2')->getFormattedValue())->toBe('10:20:00 AM')
        ->and($sheet->getStyle('I2')->getNumberFormat()->getFormatCode())->toBe('[h]:mm')
        ->and($sheet->getCell('I2')->getFormattedValue())->toBe('7:14')
        ->and((float) $sheet->getCell('L2')->getValue())->toBe(150.47)
        ->and((float) $sheet->getCell('M2')->getValue())->toBe(152.0)
        ->and((float) $sheet->getCell('N2')->getValue())->toBe(1.53)
        ->and($sheet->getCell('O2')->getFormattedValue())->toBe('1.01%')
        ->and($sheet->getCell('L3')->getValue())->toBeNull()
        ->and($sheet->getStyle('D2')->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_CENTER);

    $summary = $book->getSheetByName('Summary');
    $summaryHeader = $summary->getStyle('A'.MonthlyAttendanceExcel::SUMMARY_HEADER_ROW);
    expect($summaryHeader->getFont()->getBold())->toBeTrue()
        ->and($summaryHeader->getAlignment()->getWrapText())->toBeTrue()
        ->and($summary->getFreezePane())->toBe('A'.(MonthlyAttendanceExcel::SUMMARY_HEADER_ROW + 1));
});

it('seeds Half day, Short leave and On Duty for every new company, idempotently', function () {
    $company = aitCompany('Seeded Leave Types');
    app(AttendanceLeaveTypeSeeder::class)->provisionForCompany($company); // second run: no duplicates

    $types = LeaveType::query()->where('company_id', $company->id)->whereIn('name', ['Half day', 'Short leave', 'On Duty'])->get()->keyBy('name');

    expect($types)->toHaveCount(3)
        ->and($types['Half day']->request_unit instanceof BackedEnum ? $types['Half day']->request_unit->value : $types['Half day']->request_unit)->toBe('half_day')
        ->and($types['Short leave']->request_unit instanceof BackedEnum ? $types['Short leave']->request_unit->value : $types['Short leave']->request_unit)->toBe('hour')
        ->and($types['Short leave']->requires_allocation)->toBe('no')
        ->and($types['On Duty']->requires_allocation)->toBe('no')
        ->and(preg_match((string) config('attendance.monthly_report.on_duty_leave_type_pattern'), 'On Duty'))->toBe(1);
});
