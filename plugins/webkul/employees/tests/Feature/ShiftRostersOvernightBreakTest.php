<?php

/**
 * HR parity plan 3.4 / 3.5: shift definitions, rotating rosters with day
 * overrides and swaps, overnight shifts (attendance date = the start date,
 * after-midnight GPS / device / manual punches, late and early, Today's
 * attendance, the month boundary and lock), unpaid break deduction, company
 * isolation, the Shift roster page and the employee's upcoming shifts.
 */

require_once __DIR__.'/Attendance/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Employee\Exceptions\AttendanceMonthLockedException;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Pages\ShiftRosterCalendar;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\ShiftOverride;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\DeviceAttendanceImportService;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceExcel;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\ShiftRosterExcel;
use Webkul\Employee\Services\Attendance\ShiftRosterService;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Support\DefaultShifts;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

beforeEach(function () {
    // Saturday 5 Sep 2026, 11:00 in Karachi (UTC+5): August is over.
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
        'attendance.shifts.overnight_tolerance_minutes'        => 240,
    ]);

    $this->resolver = app(AttendanceScheduleResolver::class);
    $this->rosters = app(ShiftRosterService::class);
    $this->report = app(MonthlyAttendanceReport::class);
});

function sroCompany(string $name = 'ShipLine Roster Test'): Company
{
    return Company::factory()->create(['name' => $name.' '.Str::random(4), 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function sroUser(Company $company, array $permissions = [], ?string $role = null): User
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

    // The app's own registrar (Webkul\Security) caches the permission list, as in GeoTestHelpers.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** HR of the company: rosters, attendance, finalising months. */
function sroHr(Company $company): User
{
    return sroUser($company, [
        HrPermissions::ViewAllRecords,
        HrPermissions::ViewAttendance,
        HrPermissions::ManageAttendance,
        HrPermissions::FinaliseAttendance,
    ], 'sro_hr');
}

function sroEmployee(Company $company, string $name = 'Warehouse Worker', array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id'        => $company->id,
        'name'              => $name.' '.Str::random(3),
        'is_active'         => true,
        'employment_status' => 'active',
        'time_zone'         => 'Asia/Karachi',
    ]);
}

function sroShift(Company $company, string $code): Shift
{
    return Shift::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
}

/** @param  array<int, array{0: ?string, 1: int}>  $steps  [shift code or null (off), days] */
function sroPattern(Company $company, array $steps, string $name = 'Test rotation'): ShiftRoster
{
    return ShiftRoster::query()->create([
        'company_id' => $company->id,
        'name'       => $name.' '.Str::random(4),
        'pattern'    => array_map(fn (array $step): array => ['shift_id' => $step[0] === null ? null : sroShift($company, $step[0])->id, 'days' => $step[1]], $steps),
    ]);
}

/** A punch pair in Karachi local time; an out at or before the in is the next morning. */
function sroPunch(Employee $employee, string $date, string $in, ?string $out, ?string $inDate = null): AttendanceRecord
{
    $inMoment = CarbonImmutable::parse(($inDate ?? $date).' '.$in, 'Asia/Karachi');
    $outMoment = $out === null ? null : CarbonImmutable::parse(($inDate ?? $date).' '.$out, 'Asia/Karachi');
    if ($outMoment !== null && $outMoment->lessThanOrEqualTo($inMoment)) {
        $outMoment = $outMoment->addDay();
    }

    return AttendanceRecord::query()->create([
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => $date,
        'check_in'        => $inMoment->utc()->toDateTimeString(),
        'check_out'       => $outMoment?->utc()->toDateTimeString(),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
}

/** @return array<string, mixed> */
function sroRow(Employee $employee, string $date): array
{
    return collect(app(MonthlyAttendanceReport::class)->forEmployee($employee, (int) $employee->company_id, substr($date, 0, 7))['rows'])->keyBy('date')[$date];
}

function sroCode(Employee $employee, string $date): string
{
    return app(ShiftRosterService::class)->cell(app(AttendanceScheduleResolver::class)->scheduleFor($employee, $date), $date)['code'];
}

// ---------------------------------------------------------------------
// Shift definitions
// ---------------------------------------------------------------------

it('gives every company the default shifts and the sample rotation, idempotently', function () {
    $company = sroCompany();

    $shifts = Shift::query()->where('company_id', $company->id)->orderBy('sort')->get();
    expect($shifts->pluck('code')->all())->toBe(['G', 'M', 'E', 'N'])
        ->and($shifts->map(fn (Shift $shift): string => $shift->name.' '.$shift->hours())->all())
        ->toBe(['General 10:00–18:00', 'Morning 06:00–14:00', 'Evening 14:00–22:00', 'Night 22:00–06:00 (+1)'])
        ->and(sroShift($company, 'N')->isOvernight())->toBeTrue()
        ->and(sroShift($company, 'N')->break_minutes)->toBe(30)
        ->and(sroShift($company, 'G')->break_minutes)->toBe(0);

    $sample = ShiftRoster::query()->where('company_id', $company->id)->where('name', DefaultShifts::SamplePatternName)->sole();
    expect($sample->cycle_days)->toBe(21)
        ->and($sample->label([sroShift($company, 'M')->id => 'M', sroShift($company, 'E')->id => 'E', sroShift($company, 'N')->id => 'N']))
        ->toBe('M×5, Off×2, E×5, Off×2, N×5, Off×2');

    expect(DefaultShifts::provisionForCompany($company->id))->toBe(0)
        ->and(Shift::query()->where('company_id', $company->id)->count())->toBe(4)
        ->and(ShiftRoster::query()->where('company_id', $company->id)->count())->toBe(1);

    // A duplicate code and a zero-length shift are refused.
    expect(fn () => Shift::query()->create(['company_id' => $company->id, 'name' => 'Night 2', 'code' => 'n', 'start_time' => '23:00', 'end_time' => '07:00', 'required_minutes' => 450]))
        ->toThrow(ValidationException::class)
        ->and(fn () => Shift::query()->create(['company_id' => $company->id, 'name' => 'Odd', 'code' => 'X', 'start_time' => '09:00', 'end_time' => '09:00', 'required_minutes' => 450]))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------
// Rosters and resolution order
// ---------------------------------------------------------------------

it('rotates a roster pattern across weeks from its start date', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $employee = sroEmployee($company);
    $pattern = sroPattern($company, [['M', 5], [null, 2], ['E', 5], [null, 2], ['N', 5], [null, 2]]);

    $this->rosters->assign($hr, $pattern, $employee, '2026-08-03');

    $codes = collect(range(0, 43))->mapWithKeys(fn (int $offset): array => [
        $date = CarbonImmutable::parse('2026-08-03')->addDays($offset)->toDateString() => sroCode($employee, $date),
    ]);

    expect($codes->slice(0, 21)->values()->all())->toBe(['M', 'M', 'M', 'M', 'M', 'Off', 'Off', 'E', 'E', 'E', 'E', 'E', 'Off', 'Off', 'N', 'N', 'N', 'N', 'N', 'Off', 'Off'])
        // The cycle repeats: week 4 is week 1 again, and week 7 too.
        ->and($codes->slice(21, 21)->values()->all())->toBe($codes->slice(0, 21)->values()->all())
        ->and($codes['2026-09-14'])->toBe('M')
        // Before the start date the company default applies (Sunday = weekend).
        ->and(sroCode($employee, '2026-08-02'))->toBe('–');

    $night = $this->resolver->scheduleFor($employee, '2026-08-17');
    expect($night->isOvernight())->toBeTrue()
        ->and([$night->startClock(), $night->endClock(), $night->endSeconds])->toBe(['22:00', '06:00', 30 * 3600])
        ->and($night->breakMinutes)->toBe(30)
        ->and($this->resolver->scheduleFor($employee, '2026-08-22')->isOff())->toBeTrue();

    // A new pattern from a later date ends the open-ended one the day before (history kept).
    $this->rosters->assign($hr, sroPattern($company, [['G', 5], [null, 2]]), $employee, '2026-09-07');
    expect(sroCode($employee, '2026-09-04'))->toBe('E')
        ->and(sroCode($employee, '2026-09-07'))->toBe('G');
});

it('resolves a day override first, then the employee roster, the department roster, the work schedule and the company default', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Dispatch '.Str::random(3)]);
    $employee = sroEmployee($company, 'Dispatcher', ['department_id' => $department->id]);
    $colleague = sroEmployee($company, 'Colleague', ['department_id' => $department->id]);
    $outsider = sroEmployee($company, 'Office');

    WorkSchedule::query()->create([
        'company_id'       => $company->id, 'employee_id' => $employee->id, 'start_time' => '08:00', 'end_time' => '16:00',
        'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5, 6], 'effective_from' => '2026-08-01',
    ]);

    $tuesday = '2026-08-04';
    expect($this->resolver->scheduleFor($employee, $tuesday)->source)->toBe('schedule')
        ->and($this->resolver->scheduleFor($employee, $tuesday)->startClock())->toBe('08:00')
        ->and($this->resolver->scheduleFor($outsider, $tuesday)->isDefault())->toBeTrue();

    $this->rosters->assignDepartment($hr, sroPattern($company, [['G', 7]]), $department, '2026-08-01');
    expect(sroCode($employee, $tuesday))->toBe('G')
        ->and(sroCode($colleague, $tuesday))->toBe('G')
        ->and($this->resolver->scheduleFor($outsider, $tuesday)->isDefault())->toBeTrue();

    $this->rosters->assign($hr, sroPattern($company, [['N', 7]]), $employee, '2026-08-01');
    expect(sroCode($employee, $tuesday))->toBe('N')
        ->and(sroCode($colleague, $tuesday))->toBe('G');

    $this->rosters->setDay($hr, $employee, $tuesday, sroShift($company, 'E')->id, 'Covering the evening dispatch');
    $this->rosters->setDay($hr, $employee, '2026-08-05', null, 'Compensatory day off');

    $override = $this->resolver->scheduleFor($employee, $tuesday);
    expect($override->source)->toBe('override')
        ->and($override->shiftCode)->toBe('E')
        ->and($override->note)->toBe('Covering the evening dispatch')
        ->and($this->resolver->scheduleFor($employee, '2026-08-05')->isOff())->toBeTrue()
        ->and(ShiftOverride::query()->where('employee_id', $employee->id)->pluck('kind', 'reason')->all())
        ->toBe(['Covering the evening dispatch' => 'change', 'Compensatory day off' => 'off']);

    // A swap: each takes the other's shift that day.
    [$mine, $theirs] = $this->rosters->swap($hr, $employee, $colleague, '2026-08-06', 'Family event');
    expect(sroCode($employee, '2026-08-06'))->toBe('G')
        ->and(sroCode($colleague, '2026-08-06'))->toBe('N')
        ->and([$mine->kind, $mine->swap_employee_id, $theirs->swap_employee_id])->toBe(['swap', $colleague->id, $employee->id]);

    // Back to the roster.
    $this->rosters->clearDay($hr, $employee, $tuesday);
    expect(sroCode($employee, $tuesday))->toBe('N');

    // A roster day off is an off day in the report ("Day off"), not a working day.
    $this->rosters->setDay($hr, $outsider, '2026-08-11', null, 'Rest day after a double shift');
    expect(sroRow($outsider, '2026-08-10')['working'])->toBeTrue()
        ->and(collect($this->report->forEmployee($outsider, $company->id, '2026-08')['rows'])->firstWhere('date', '2026-08-11'))->toBeNull();
});

// ---------------------------------------------------------------------
// Overnight shifts
// ---------------------------------------------------------------------

it('attributes after-midnight GPS check-ins and check-outs to the night shift that started the day before', function () {
    $f = geoFixture(['time_zone' => 'Asia/Karachi']);
    $hr = sroHr($f['company']);
    $this->rosters->assign($hr, sroPattern($f['company'], [['N', 7]]), $f['employee'], '2026-08-17');
    $service = app(GeofencedAttendanceService::class);
    $at = fn (string $local) => $this->travelTo(CarbonImmutable::parse($local, 'Asia/Karachi')->utc());
    $evidence = fn (int $step) => geoEvidence(['latitude' => GEO_LAT + $step * 0.00001]);

    $at('2026-08-17 22:05');
    expect($service->checkIn($f['user'], $evidence(1), geoRequest())->accepted)->toBeTrue();

    $at('2026-08-18 01:00');
    expect($service->checkOut($f['user'], $evidence(2), geoRequest())->accepted)->toBeTrue();
    // Back from the break after midnight: still the 17th's shift.
    $at('2026-08-18 01:30');
    expect($service->checkIn($f['user'], $evidence(3), geoRequest())->accepted)->toBeTrue()
        ->and($service->todayState($f['user'])['scheduled_start'])->toBe('10:00 PM');
    $at('2026-08-18 06:02');
    expect($service->checkOut($f['user'], $evidence(4), geoRequest())->accepted)->toBeTrue();

    $records = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->get();
    expect($records->map(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString())->all())->toBe(['2026-08-17'])
        ->and(AttendanceInterval::query()->where('attendance_record_id', $records[0]->id)->active()->count())->toBe(2);

    // Within the 4 h tolerance after 06:00 a punch still belongs to the 17th; later it is the 18th's.
    expect($this->resolver->attendanceDateFor($f['employee'], CarbonImmutable::parse('2026-08-18 09:30', 'Asia/Karachi')->utc()))->toBe('2026-08-17')
        ->and($this->resolver->attendanceDateFor($f['employee'], CarbonImmutable::parse('2026-08-18 10:30', 'Asia/Karachi')->utc()))->toBe('2026-08-18')
        ->and($this->resolver->attendanceDateFor($f['employee'], CarbonImmutable::parse('2026-08-18 21:50', 'Asia/Karachi')->utc()))->toBe('2026-08-18');

    $row = sroRow($f['employee'], '2026-08-17');
    expect($row['intervals_text'])->toBe('22:05–01:00, 01:30–06:02')
        ->and($row['late_minutes'])->toBe(5)
        ->and($row['missing_check_out'])->toBeFalse()
        // The 30-minute gap at 01:00 was the break: nothing more is deducted.
        ->and($row['break_deducted_minutes'])->toBe(0);
});

it('fills the attendance date of a manual entry from its check-in, after midnight the night shift day', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $employee = sroEmployee($company);
    $this->rosters->assign($hr, sroPattern($company, [['N', 7]]), $employee, '2026-08-17');
    $this->actingAs($hr);

    $resource = AttendanceRecordResource::class;
    expect($resource::shiftDateFor($employee->id, CarbonImmutable::parse('2026-08-19 00:40', 'Asia/Karachi')->setTimezone(config('app.timezone'))->toDateTimeString()))->toBe('2026-08-18')
        ->and($resource::shiftDateFor($employee->id, CarbonImmutable::parse('2026-08-19 22:10', 'Asia/Karachi')->setTimezone(config('app.timezone'))->toDateTimeString()))->toBe('2026-08-19');

    // Without a roster nothing moves.
    $plain = sroEmployee($company, 'Office');
    expect($resource::shiftDateFor($plain->id, CarbonImmutable::parse('2026-08-19 00:40', 'Asia/Karachi')->setTimezone(config('app.timezone'))->toDateTimeString()))->toBe('2026-08-19');
});

it('pairs device punches of a night shift as one day instead of splitting them at midnight', function () {
    Storage::fake('local');
    $company = sroCompany();
    $hr = sroHr($company);
    $night = sroEmployee($company, 'Night Loader', ['biometric_id' => '7001']);
    $plain = sroEmployee($company, 'No Roster', ['biometric_id' => '7002']);
    $this->rosters->assign($hr, sroPattern($company, [['N', 5], [null, 2]]), $night, '2026-08-17');

    $csv = implode("\n", [
        'Employee Code / Biometric ID,Date Time,Direction',
        '7001,2026-08-17 21:58,IN',
        '7001,2026-08-18 06:04,OUT',
        '7001,2026-08-18 21:55,IN',
        '7001,2026-08-19 06:10,OUT',
        '7002,2026-08-17 21:58,IN',
        '7002,2026-08-18 06:04,OUT',
    ])."\n";
    $path = 'employees/attendance-imports/'.Str::random(8).'-night.csv';
    Storage::disk('local')->put($path, $csv);

    $service = app(DeviceAttendanceImportService::class);
    $batch = $service->import($hr, $service->preview($hr, $company->id, $path, 'night.csv'));

    $nightDays = AttendanceRecord::query()->where('employee_id', $night->id)->orderBy('attendance_date')->get();
    expect($nightDays->map(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString())->all())->toBe(['2026-08-17', '2026-08-18'])
        ->and($nightDays->pluck('verification_status')->filter()->all())->toBe([])
        ->and($batch->rows()->where('employee_id', $night->id)->pluck('attendance_date')->map(fn ($date): string => $date->toDateString())->all())
        ->toBe(['2026-08-17', '2026-08-17', '2026-08-18', '2026-08-18']);

    $row = sroRow($night, '2026-08-17');
    expect($row['intervals_text'])->toBe('21:58–06:04')
        ->and($row['missing_check_out'])->toBeFalse()
        ->and($row['late_minutes'])->toBe(0)
        // 8h inside 22:00-06:00 less the 30 min unpaid break, plus 6 min outside it.
        ->and(intdiv($row['final_seconds'], 60))->toBe(456)
        ->and($row['break_deducted_minutes'])->toBe(30);

    // Without a roster the calendar day rule is unchanged: two days, the second one open for review.
    $plainDays = AttendanceRecord::query()->where('employee_id', $plain->id)->orderBy('attendance_date')->get();
    expect($plainDays->map(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString())->all())->toBe(['2026-08-17', '2026-08-18'])
        ->and($plainDays[1]->check_out)->toBeNull();
});

it('measures late and leaving early on a night shift across midnight, and shows it on Today\'s attendance', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $employee = sroEmployee($company);
    $this->rosters->assign($hr, sroPattern($company, [['N', 7]]), $employee, '2026-08-17');

    sroPunch($employee, '2026-08-17', '22:20', '06:00');
    // The 18th's shift: checked in at 00:30 on the 19th.
    sroPunch($employee, '2026-08-18', '00:30', '06:00', '2026-08-19');
    // The 19th's shift: left at 05:00 on the 20th.
    sroPunch($employee, '2026-08-19', '22:00', '05:00');

    expect(sroRow($employee, '2026-08-17'))->toMatchArray(['late' => true, 'late_minutes' => 20, 'left_early_minutes' => 0])
        ->and(sroRow($employee, '2026-08-18'))->toMatchArray(['late' => true, 'late_minutes' => 150, 'left_early_minutes' => 0, 'gap_minutes' => 0])
        ->and(sroRow($employee, '2026-08-19'))->toMatchArray(['late' => false, 'late_minutes' => 0, 'left_early_minutes' => 60, 'gap_minutes' => 0])
        ->and(sroRow($employee, '2026-08-18')['remarks'])->toContain('Late');

    // 01:00 on the 21st: the 20th's night shift is running, checked in late at 00:40.
    $this->travelTo(CarbonImmutable::parse('2026-08-21 01:00', 'Asia/Karachi')->utc());
    sroPunch($employee, '2026-08-20', '00:40', null, '2026-08-21');

    $yesterday = app(TodayAttendance::class)->forCompany($hr, $company->id, '2026-08-20')['rows'][$employee->id];
    expect($yesterday)->toMatchArray([
        'status'            => TodayAttendance::LATE,
        'currently_in'      => true,
        'missing_check_out' => false,
        'late_minutes'      => 160,
        'shift'             => 'N',
        'worked_seconds'    => 20 * 60,
    ]);
});

it('keeps an overnight shift on the last day of the month in that month, and the month lock follows it', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $employee = sroEmployee($company);
    $this->rosters->assign($hr, sroPattern($company, [['N', 5], [null, 2]]), $employee, '2026-08-24');

    $last = sroPunch($employee, '2026-08-31', '22:00', '06:00');
    expect($last->check_out->toDateTimeString())->toBe('2026-09-01 01:00:00');

    $august = $this->report->forEmployee($employee, $company->id, '2026-08');
    $row = collect($august['rows'])->keyBy('date')['2026-08-31'];
    expect($row['intervals_text'])->toBe('22:00–06:00')
        ->and($row['final_seconds'])->toBe(450 * 60)
        ->and($row['required_seconds'])->toBe(450 * 60)
        ->and($row['break_deducted_minutes'])->toBe(30)
        ->and($august['summary']['break_deducted_minutes'])->toBe(30);

    $september = collect($this->report->forEmployee($employee, $company->id, '2026-09')['rows'])->keyBy('date');
    expect($september['2026-09-01']['first_in'])->toBeNull()
        ->and($september['2026-09-01']['absent'])->toBeTrue();

    app(AttendanceMonthLockService::class)->finalise($hr, $company->id, '2026-08');

    // The 06:00 check-out on 1 September belongs to the 31 August shift: locked with August.
    expect(fn () => $last->fresh()->update(['check_out' => CarbonImmutable::parse('2026-09-01 07:00', 'Asia/Karachi')->utc()->toDateTimeString()]))
        ->toThrow(AttendanceMonthLockedException::class)
        ->and(fn () => $this->rosters->setDay($hr, $employee, '2026-08-31', null, 'Too late for August'))
        ->toThrow(AttendanceMonthLockedException::class);

    // September is open.
    expect($this->rosters->setDay($hr, $employee, '2026-09-01', null, 'Rest day')->exists)->toBeTrue()
        ->and(sroPunch($employee, '2026-09-02', '22:00', '06:00')->exists)->toBeTrue();
});

// ---------------------------------------------------------------------
// Break deduction
// ---------------------------------------------------------------------

it('deducts the unpaid break from a single interval but not again from a recorded break', function () {
    $schedule = ['start' => 10 * 3600, 'end' => 18 * 3600, 'required' => 450, 'break' => 30];
    $at = fn (string $time): int => (int) explode(':', $time)[0] * 3600 + (int) explode(':', $time)[1] * 60;
    $day = fn (array $pairs): array => $this->report->calculateDay([
        'intervals'          => array_map(fn (array $pair): array => [$at($pair[0]), $at($pair[1])], $pairs),
        'schedule'           => $schedule,
        'credit_cap_minutes' => 60,
    ]);

    $single = $day([['10:00', '18:00']]);
    expect($single)->toMatchArray(['break_deducted_minutes' => 30, 'final_seconds' => 450 * 60, 'gap_minutes' => 0, 'net_seconds' => 0])
        ->and($single['remarks'])->toContain('Break deducted 30 min');

    $split = $day([['10:00', '13:50'], ['14:20', '18:00']]);
    expect($split)->toMatchArray(['break_deducted_minutes' => 0, 'final_seconds' => 450 * 60, 'gap_minutes' => 0])
        ->and($split['remarks'])->not->toContain('Break deducted');

    // A 10-minute break recorded: the other 20 minutes come off.
    expect($day([['10:00', '13:55'], ['14:05', '18:00']]))->toMatchArray(['break_deducted_minutes' => 20, 'final_seconds' => 450 * 60]);

    // An hour away: nothing deducted, and the 30 minutes beyond the break are time away.
    $long = $day([['10:00', '13:30'], ['14:30', '18:00']]);
    expect($long)->toMatchArray(['break_deducted_minutes' => 0, 'final_seconds' => 420 * 60, 'gap_minutes' => 30]);

    // Gone before the break window (13:45-14:15): no break taken, none deducted.
    expect($day([['10:00', '14:00']])['break_deducted_minutes'])->toBe(0);

    // Overnight: the window is around 02:00.
    expect($this->report->calculateDay([
        'intervals' => [[22 * 3600, 30 * 3600]],
        'schedule'  => ['start' => 22 * 3600, 'end' => 30 * 3600, 'required' => 450, 'break' => 30],
    ]))->toMatchArray(['break_deducted_minutes' => 30, 'final_seconds' => 450 * 60, 'late_minutes' => 0, 'left_early_minutes' => 0]);

    // No break configured: exactly as before.
    expect($this->report->calculateDay(['intervals' => [[$at('10:00'), $at('18:00')]], 'schedule' => ['start' => 36000, 'end' => 64800, 'required' => 480]]))
        ->toMatchArray(['break_deducted_minutes' => 0, 'final_seconds' => 480 * 60]);
});

it('shows the deducted break in the monthly summary and the per-employee Excel sheet', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $withBreak = sroEmployee($company, 'Break Person');
    $plain = sroEmployee($company, 'Plain Person');
    WorkSchedule::query()->create([
        'company_id'       => $company->id, 'employee_id' => $withBreak->id, 'start_time' => '10:00', 'end_time' => '18:00',
        'required_minutes' => 450, 'break_minutes' => 30, 'working_days' => [1, 2, 3, 4, 5], 'effective_from' => '2026-08-01',
    ]);

    sroPunch($withBreak, '2026-08-03', '10:00', '18:00');
    sroPunch($withBreak, '2026-08-04', '10:00', '13:45');
    sroPunch($plain, '2026-08-03', '10:00', '18:00');

    $report = $this->report->forCompany($hr, $company->id, '2026-08', null, [$withBreak->id, $plain->id]);
    $byName = collect($report['employees'])->keyBy(fn (array $employee): string => $employee['employee']['name']);

    expect($byName[$withBreak->name]['summary']['break_deducted_minutes'])->toBe(30)
        ->and($byName[$plain->name]['summary']['break_deducted_minutes'])->toBe(0)
        ->and($byName[$withBreak->name]['schedule']['break_minutes'])->toBe(30)
        ->and($this->report->extraHeaders())->toContain('Break deducted (mins)');

    $sheet = app(MonthlyAttendanceExcel::class)->build($report)->getSheetByName($withBreak->name);
    $cells = $sheet->toArray(null, false, false, false);
    $header = collect($cells)->search(fn (array $line): bool => in_array('Break deducted (mins)', $line, true));
    $column = array_search('Break deducted (mins)', $cells[$header], true);
    $dayRow = collect($cells)->first(fn (array $line): bool => str_contains((string) ($line[3] ?? ''), 'Break deducted 30 min'));

    expect($header)->not->toBeFalse()
        ->and($dayRow)->not->toBeNull()
        ->and((int) $dayRow[$column])->toBe(30);
});

// ---------------------------------------------------------------------
// Company isolation and scope
// ---------------------------------------------------------------------

it('keeps companies apart and lets line managers change only their own team', function () {
    $shipLine = sroCompany('ShipLine');
    $openPort = sroCompany('OpenPort');
    $hr = sroHr($shipLine);
    $mine = sroEmployee($shipLine, 'ShipLine Worker');
    $theirs = sroEmployee($openPort, 'OpenPort Worker');
    $shipLinePattern = sroPattern($shipLine, [['N', 5], [null, 2]]);

    // Another company's employee, pattern or shift is refused.
    expect(fn () => $this->rosters->assign($hr, $shipLinePattern, $theirs, '2026-08-03'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->rosters->assign(sroHr($openPort), $shipLinePattern, $theirs, '2026-08-03'))->toThrow(ValidationException::class)
        ->and(fn () => ShiftOverride::query()->create(['employee_id' => $theirs->id, 'date' => '2026-08-03', 'shift_id' => sroShift($shipLine, 'N')->id, 'reason' => 'Cross-company']))
        ->toThrow(ValidationException::class)
        ->and(fn () => ShiftRoster::query()->create(['company_id' => $openPort->id, 'name' => 'Borrowed', 'pattern' => [['shift_id' => sroShift($shipLine, 'N')->id, 'days' => 5]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->rosters->calendar($hr, $openPort->id, '2026-08-03', 7))->toThrow(AuthorizationException::class);

    $this->rosters->assign($hr, $shipLinePattern, $mine, '2026-08-03');
    expect(sroCode($theirs, '2026-08-03'))->toBe('G')
        ->and(collect($this->rosters->calendar($hr, $shipLine->id, '2026-08-03', 7)['rows'])->pluck('employee_id')->all())->toContain($mine->id)
        ->and(collect($this->rosters->calendar($hr, $shipLine->id, '2026-08-03', 7)['rows'])->pluck('employee_id')->all())->not->toContain($theirs->id);

    // A line manager (manage attendance, own team only) changes their report's day, not someone else's, never their own.
    $managerUser = sroUser($shipLine, [HrPermissions::ManageAttendance], 'sro_line_manager');
    $manager = sroEmployee($shipLine, 'Shift Lead', ['user_id' => $managerUser->id]);
    $report = sroEmployee($shipLine, 'Team Member', ['parent_id' => $manager->id]);

    expect($this->rosters->setDay($managerUser, $report, '2026-08-10', null, 'Swap requested')->exists)->toBeTrue()
        ->and(fn () => $this->rosters->setDay($managerUser, $mine, '2026-08-10', null, 'Not my team'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->rosters->setDay($managerUser, $manager, '2026-08-10', null, 'Myself'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->rosters->assignDepartment($managerUser, $shipLinePattern, Department::query()->create(['company_id' => $shipLine->id, 'name' => 'Yard '.Str::random(3)]), '2026-08-03'))
        ->toThrow(AuthorizationException::class);

    $calendar = $this->rosters->calendar($managerUser, $shipLine->id, '2026-08-10', 7);
    expect(collect($calendar['rows'])->pluck('employee_id')->all())->not->toContain($mine->id)
        ->and(collect($calendar['rows'])->firstWhere('employee_id', $report->id)['can_edit'])->toBeTrue()
        ->and(collect($calendar['rows'])->firstWhere('employee_id', $report->id)['cells']['2026-08-10']['code'])->toBe('Off');
});

// ---------------------------------------------------------------------
// Screens
// ---------------------------------------------------------------------

it('renders the shift roster page fast, changes a day from it and exports it', function () {
    $company = sroCompany();
    $hr = sroHr($company);
    $pattern = sroPattern($company, [['M', 5], [null, 2], ['N', 5], [null, 2]]);
    $employees = collect(range(1, 25))->map(fn (int $index): Employee => sroEmployee($company, sprintf('Roster %02d', $index)));
    foreach ($employees->take(12) as $employee) {
        $this->rosters->assign($hr, $pattern, $employee, '2026-08-31');
    }
    $this->rosters->assign($hr, sroPattern($company, [['N', 7]], 'Nights only'), $employees[20], '2026-08-31');
    $this->rosters->setDay($hr, $employees[0], '2026-09-01', sroShift($company, 'E')->id, 'Covering');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($hr);

    // Warm up, then drop the after-response work queued while the test data was created (as HomeDashboardTest does).
    Livewire::test(ShiftRosterCalendar::class);
    (new ReflectionProperty(app(), 'terminatingCallbacks'))->setValue(app(), []);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = microtime(true);
    $page = Livewire::test(ShiftRosterCalendar::class);
    $elapsed = microtime(true) - $started;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $page->assertOk()
        ->assertSee('Shift roster')
        ->assertSee($employees[0]->name)
        ->assertSee('Night 22:00–06:00 (+1)')
        ->assertSee('Covering');

    // 25 employees x 14 days in a flat number of queries.
    expect($queries)->toBeLessThanOrEqual(25)
        ->and($elapsed)->toBeLessThan(3.0);

    $page->callAction(TestAction::make('editDay')->arguments(['employee' => $employees[1]->id, 'date' => '2026-09-02']), ['mode' => 'off', 'reason' => 'Doctor appointment'])
        ->assertHasNoActionErrors();
    expect(sroCode($employees[1], '2026-09-02'))->toBe('Off');

    $page->callAction(TestAction::make('editDay')->arguments(['employee' => $employees[2]->id, 'date' => '2026-09-02']), ['mode' => 'swap', 'swap_employee_id' => $employees[20]->id, 'reason' => 'Family event']);
    expect(sroCode($employees[2], '2026-09-02'))->toBe('N')
        ->and(sroCode($employees[20], '2026-09-02'))->toBe('M');

    $page->callAction('assignRoster', ['roster_id' => $pattern->id, 'target' => 'employees', 'employee_ids' => [$employees[13]->id, $employees[14]->id], 'start_date' => '2026-09-07']);
    expect(sroCode($employees[13], '2026-09-07'))->toBe('M');

    $calendar = $this->rosters->calendar($hr, $company->id, '2026-08-31', 7);
    $sheet = app(ShiftRosterExcel::class)->build($calendar)->getActiveSheet();
    $firstRow = collect($sheet->toArray(null, false, false, false))->first(fn (array $line): bool => ($line[1] ?? null) === $employees[0]->name);
    expect(array_slice($firstRow, 3, 7))->toBe(['M', 'E*', 'M', 'M', 'M', 'Off', 'Off']);
});

it('shows employees their own upcoming shifts on My Attendance and the home dashboard', function () {
    config(['hr_attendance_geofence.enabled' => true]);
    $f = geoFixture(['time_zone' => 'Asia/Karachi']);
    $hr = sroHr($f['company']);
    $this->rosters->assign($hr, sroPattern($f['company'], [['N', 2], [null, 1], ['M', 4]]), $f['employee'], '2026-09-05');
    $this->rosters->setDay($hr, $f['employee'], '2026-09-08', sroShift($f['company'], 'E')->id, 'Inventory count');

    $upcoming = $this->rosters->upcomingFor($f['employee'], '2026-09-05', 5);
    expect(array_column($upcoming, 'code'))->toBe(['N', 'N', 'Off', 'E', 'M'])
        ->and($upcoming[3]['changed'])->toBeTrue()
        ->and($upcoming[3]['note'])->toBe('Inventory count')
        // Not rostered: nothing to show.
        ->and($this->rosters->upcomingFor(sroEmployee($f['company']), '2026-09-05', 5))->toBe([]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('My upcoming shifts')
        ->assertSee('Night')
        ->assertSee('22:00–06:00 (+1)');

    $this->get('/admin')
        ->assertOk()
        ->assertSee('data-testid="my-day-shifts"', false)
        ->assertSee('Night 22:00–06:00 (+1)');
});
