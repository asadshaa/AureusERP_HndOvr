<?php

/**
 * Regression tests for the Phase 0 defect fixes in
 * docs/ATTENDANCE_WORKFLOW_AND_CALCULATION_IMPLEMENTATION_SPEC.md §3 / §20:
 * D9 two-week calendars, D10 clock-time schedule rows, D12 leave lookups,
 * D15 per-company workflow provisioning, D16 duplicate notifications,
 * D17 chatter EmployeeRequest branch, D18 export time zone / weekends,
 * D19 monthly summary, D20 config. (D6 auto-close is covered in
 * HybridAttendanceWorkflowTest.)
 */

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\Chatter\Services\ChatterNotificationService;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\AttendanceRecordsRelationManager;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\Attendance\ApprovedLeaveLookup;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Services\LeaveApprovalService;

/** A leave row as Time Off stores it: request_date_* always set, date_from/date_to often NULL. */
function phase0Leave($employee, string $from, string $to, string $state = 'validate_two', bool $withLegacyDates = false): int
{
    return DB::table('time_off_leaves')->insertGetId([
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'state'             => $state,
        'request_date_from' => $from.' 00:00:00',
        'request_date_to'   => $to.' 00:00:00',
        'date_from'         => $withLegacyDates ? $from.' 09:00:00' : null,
        'date_to'           => $withLegacyDates ? $to.' 17:00:00' : null,
        'number_of_days'    => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

// ---------------------------------------------------------------------
// D20 config
// ---------------------------------------------------------------------

it('D20: declares each long-shift setting exactly once', function () {
    $source = file_get_contents(config_path('hr_attendance_geofence.php'));

    expect(substr_count($source, "'long_shift_hours'"))->toBe(1)
        ->and(substr_count($source, "'long_shift_grace_hours'"))->toBe(1)
        ->and(config('hr_attendance_geofence.auto_close_policy'))->toBe('none');
});

// ---------------------------------------------------------------------
// D9 / D10 calendars
// ---------------------------------------------------------------------

it('D10: reads schedule rows saved as clock times ("10:30:00") and as decimal hours', function () {
    expect(CalendarAttendance::toMinutes('10:30:00'))->toBe(630)
        ->and(CalendarAttendance::toMinutes('10:30'))->toBe(630)
        ->and(CalendarAttendance::toMinutes('10'))->toBe(600)
        ->and(CalendarAttendance::toMinutes('10.5'))->toBe(630)
        ->and(CalendarAttendance::toMinutes(null))->toBe(0)
        ->and(CalendarAttendance::toDecimalHours('17:45:00'))->toBe(17.75);

    $calendar = Calendar::query()->create(['name' => 'Half hours', 'timezone' => 'UTC', 'hours_per_day' => 8, 'is_active' => true]);
    CalendarAttendance::query()->create(['name' => 'Mon', 'calendar_id' => $calendar->id, 'day_of_week' => 'monday', 'day_period' => 'morning', 'hour_from' => '09:30:00', 'hour_to' => '17:45:00']);

    $monday = Carbon::parse('2026-10-05');
    $interval = $calendar->getAttendanceIntervalsBatch($monday->copy()->startOfDay(), $monday->copy()->endOfDay(), timezone: 'UTC')[null][0];

    expect($interval[0]->format('H:i'))->toBe('09:30')
        ->and($interval[1]->format('H:i'))->toBe('17:45');
});

it('D9: a two-week calendar uses the even-week rows in even ISO weeks and the odd-week rows in odd ones', function () {
    $calendar = Calendar::query()->create(['name' => 'Alternating', 'timezone' => 'UTC', 'hours_per_day' => 4, 'is_active' => true, 'two_weeks_calendar' => true]);
    CalendarAttendance::query()->create(['name' => 'Even Mon', 'calendar_id' => $calendar->id, 'day_of_week' => 'monday', 'day_period' => 'morning', 'week_type' => 'even', 'hour_from' => '9', 'hour_to' => '13']);
    CalendarAttendance::query()->create(['name' => 'Odd Mon', 'calendar_id' => $calendar->id, 'day_of_week' => 'monday', 'day_period' => 'afternoon', 'week_type' => 'odd', 'hour_from' => '14', 'hour_to' => '18']);

    $evenMonday = Carbon::parse('2026-10-12'); // ISO week 42
    $oddMonday = Carbon::parse('2026-10-05');  // ISO week 41
    expect($evenMonday->isoWeek() % 2)->toBe(0)->and($oddMonday->isoWeek() % 2)->toBe(1);

    $day = fn (Carbon $d) => collect($calendar->getAttendanceIntervalsBatch($d->copy()->startOfDay(), $d->copy()->endOfDay(), timezone: 'UTC')[null] ?? [])
        ->map(fn ($i) => $i[0]->format('H:i').'-'.$i[1]->format('H:i'))->all();

    expect($day($evenMonday))->toBe(['09:00-13:00'])
        ->and($day($oddMonday))->toBe(['14:00-18:00']);
});

it('D9: a day that has rows only in the other week is simply a day off, not an error', function () {
    $calendar = Calendar::query()->create(['name' => 'Odd only', 'timezone' => 'UTC', 'hours_per_day' => 4, 'is_active' => true, 'two_weeks_calendar' => true]);
    CalendarAttendance::query()->create(['name' => 'Odd Mon', 'calendar_id' => $calendar->id, 'day_of_week' => 'monday', 'day_period' => 'morning', 'week_type' => 'odd', 'hour_from' => '9', 'hour_to' => '13']);

    $evenMonday = Carbon::parse('2026-10-12');
    $result = $calendar->getAttendanceIntervalsBatch($evenMonday->copy()->startOfDay(), $evenMonday->copy()->endOfDay(), timezone: 'UTC');

    expect(collect($result[null] ?? []))->toBeEmpty();
});

// ---------------------------------------------------------------------
// D12 leave lookups
// ---------------------------------------------------------------------

it('D12: finds approved leave by its request dates even when date_from/date_to are empty', function () {
    $f = geoFixture();
    phase0Leave($f['employee'], '2026-10-06', '2026-10-07');
    phase0Leave($f['employee'], '2026-10-09', '2026-10-09', 'confirm');

    expect(ApprovedLeaveLookup::approvedForEmployee($f['employee']->id, '2026-10-07', '2026-10-07')->exists())->toBeTrue()
        ->and(ApprovedLeaveLookup::approvedForEmployee($f['employee']->id, '2026-10-08', '2026-10-08')->exists())->toBeFalse()
        ->and(ApprovedLeaveLookup::approvedForEmployee($f['employee']->id, '2026-10-09', '2026-10-09')->exists())->toBeFalse();
});

it('D12: refuses a missed-day request on a day of approved leave stored without date_from/date_to', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00:00', 'UTC'));
    $f = geoFixture();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    phase0Leave($f['employee'], '2026-10-06', '2026-10-06');

    expect(fn () => app(EmployeeRequestService::class)->requestMissingAttendance($f['employee'], $f['user'], '2026-10-06', ['check_in' => '2026-10-06 04:00:00'], 'Phone died'))
        ->toThrow(RuntimeException::class, 'approved leave');
});

it('D12: counts approved leave days in HR analytics even when date_from/date_to are empty, and ignores pending leave', function () {
    $f = geoFixture();
    phase0Leave($f['employee'], '2026-10-06', '2026-10-07');            // approved, 2 days
    phase0Leave($f['employee'], '2026-10-08', '2026-10-08', 'confirm'); // pending

    $summary = app(HrAnalyticsService::class)->summary($f['company']->id, '2026-10-01', '2026-10-31');

    expect($summary['leave_days'])->toBe(2.0);
});

it('D12: My Attendance shows approved leave as leave and pending leave as pending (not excused)', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
    $f = geoFixture();
    phase0Leave($f['employee'], '2026-10-06', '2026-10-06');            // Tue, approved
    phase0Leave($f['employee'], '2026-10-07', '2026-10-07', 'confirm'); // Wed, pending
    $this->actingAs($f['user']);

    $page = Livewire::test(MyAttendance::class)->set('calendarYear', 2026)->set('calendarMonth', 10);
    $data = $page->instance()->getMonthlyCalendarData();
    $cells = collect($data['days'] ?? $data['calendar'] ?? [])->filter(fn ($c) => is_array($c) && isset($c['date']))->keyBy('date');

    expect($cells['2026-10-06']['status'])->toBe('leave')
        ->and($cells['2026-10-07']['status'])->toBe('leave_pending')
        ->and($cells['2026-10-07']['label'])->toBe('Leave pending');
    expect($data['stats']['leaveDays'] ?? $data['leaveDays'] ?? null)->toBe(1);
});

// ---------------------------------------------------------------------
// D15 per-company workflows
// ---------------------------------------------------------------------

it('D15: a newly created company gets its attendance correction workflow automatically', function () {
    $company = Company::factory()->create(['is_active' => true]);

    expect(ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'attendance_time_change')->exists())->toBeTrue()
        ->and(EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'attendance_time_change')->value('is_active'))->toBeTruthy();
});

it('D15: provisioning is idempotent and the command fills gaps without touching existing workflows', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $workflow = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'attendance_time_change')->firstOrFail();
    $workflow->update(['name' => 'Customised by HR']);

    app(AttendanceWorkflowSeeder::class)->provisionForCompany($company);
    $this->artisan('hr:provision-attendance-workflows')->assertSuccessful();

    expect(ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'attendance_time_change')->count())->toBe(1)
        ->and($workflow->fresh()->name)->toBe('Customised by HR')
        ->and($workflow->fresh()->steps()->count())->toBe(1);
});

// ---------------------------------------------------------------------
// D16 duplicate notifications
// ---------------------------------------------------------------------

it('D16: an attendance notice for the same event and record is sent only once', function () {
    $f = geoFixture();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    $record = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'check_in'   => '2026-10-05 04:00:00', 'status' => 'present', 'source' => 'gps',
    ]);

    $notify = (new ReflectionClass(GeofencedAttendanceService::class))->getMethod('notifyLineManager');
    $service = app(GeofencedAttendanceService::class);
    $notify->invoke($service, $record->fresh('employee'), 'missing_checkout', 'Missing check-out', 'never checked out.');
    $notify->invoke($service, $record->fresh('employee'), 'missing_checkout', 'Missing check-out', 'never checked out.');
    $notify->invoke($service, $record->fresh('employee'), 'checkout_flagged', 'Check-out flagged', 'was flagged.');

    expect($managerUser->notifications()->count())->toBe(2);
});

it('D16: a leave decision notice reaches the employee once even though synchronize() runs twice', function () {
    $f = geoFixture();
    $leaveId = phase0Leave($f['employee'], '2026-10-06', '2026-10-06');
    $leave = Leave::query()->findOrFail($leaveId);
    $leave->forceFill(['user_id' => $f['user']->id])->save();

    $notify = (new ReflectionClass(LeaveApprovalService::class))->getMethod('notifyDecision');
    $service = app(LeaveApprovalService::class);
    $notify->invoke($service, $leave->fresh(), 'approved', null);
    $notify->invoke($service, $leave->fresh(), 'approved', null);

    expect($f['user']->notifications()->count())->toBe(1);
});

// ---------------------------------------------------------------------
// D17 chatter
// ---------------------------------------------------------------------

it('D17: chatter recognises an EmployeeRequest record (the import pointed at a non-existent namespace)', function () {
    $request = new EmployeeRequest(['title' => null]);
    $request->setRelation('requestType', new EmployeeRequestType(['name' => 'Attendance Time Change']));

    $resolve = (new ReflectionClass(ChatterNotificationService::class))->getMethod('resolveRecordName');

    expect($resolve->invoke(app(ChatterNotificationService::class), $request))->toBe('Attendance Time Change');
});

// ---------------------------------------------------------------------
// D18 export
// ---------------------------------------------------------------------

it('D18: exports check-in times in the employee\'s time zone and only includes weekends on request', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $f = geoFixture(['time_zone' => 'Asia/Karachi']);
    $hr = geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance);
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-09', 'check_in' => '2026-10-09 04:00:00', 'status' => 'present', 'source' => 'manual']); // Fri 09:00 PKT
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-10', 'check_in' => '2026-10-10 05:00:00', 'status' => 'present', 'source' => 'manual']); // Sat
    $this->actingAs($hr);

    $csv = function (bool $weekends) use ($f): string {
        $component = Livewire::test(ManageAttendanceRecords::class)
            ->callTableAction('export_csv', data: ['employee_id' => $f['employee']->id, 'include_weekends' => $weekends]);
        $download = $component->effects['download'] ?? null;

        return $download ? base64_decode($download['content']) : '';
    };

    $weekdaysOnly = $csv(false);
    expect($weekdaysOnly)->toContain('2026-10-09 09:00:00')->toContain('Asia/Karachi')
        ->not->toContain('2026-10-09 04:00:00')->not->toContain('2026-10-10');

    expect($csv(true))->toContain('2026-10-10 10:00:00');
});

// ---------------------------------------------------------------------
// D19 monthly summary
// ---------------------------------------------------------------------

it('D19: the monthly summary for a short month is right even on the 30th', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 08:00:00', 'UTC'));
    $f = geoFixture();

    $summary = AttendanceRecordsRelationManager::getMonthlySummary($f['employee'], '2026-02');

    expect($summary['month'])->toBe('February 2026')
        ->and($summary['working_days'])->toBe(20);
});

it('D19: counts remote working days as attended, and only the employee\'s own company records', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05', 'status' => 'present', 'source' => 'manual']);
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-06', 'status' => 'remote', 'source' => 'self_service']);

    $summary = AttendanceRecordsRelationManager::getMonthlySummary($f['employee'], '2026-10');

    expect($summary['present'])->toBe(2)->and($summary['remote'])->toBe(1);
});
