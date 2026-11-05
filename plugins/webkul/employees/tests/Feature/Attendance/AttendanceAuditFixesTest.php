<?php

/**
 * Regression tests for the attendance audit fixes: typed correction times in
 * the employee's timezone (X1, X14), targeted interval corrections (X2),
 * half-day classification (X3 / D3), one schedule rule for the record, Today's
 * attendance and the monthly report (X4 / D13), HR-approver notices (X5),
 * missed-day schedule + status (D11), the employee Attendance tab (X12), the
 * shift commands (X11), overtime_hours from approved overtime, and a second
 * check-in keeping the day's review outcome.
 */

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceVerificationStatus as Status;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\AttendanceRecordsRelationManager;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\AttendanceIntervalService;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\AttendanceStatusClassifier;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\OvertimeService;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Cache::flush();
    config([
        'attendance.monthly_report.office_start'               => '10:00',
        'attendance.monthly_report.office_end'                 => '18:00',
        'attendance.monthly_report.daily_required_minutes'     => 480,
        'attendance.monthly_report.late_grace_minutes'         => 0,
        'attendance.monthly_report.timezone'                   => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'               => [6, 7],
        'attendance.notifications.late_check_in'               => false,
        'hr_attendance_geofence.full_day_tolerance_minutes'    => 30,
        'hr_attendance_geofence.min_checkout_interval_minutes' => 60,
    ]);
});

/** Karachi local "H:i" on $date as a UTC moment. */
function axfUtc(string $date, string $time): CarbonImmutable
{
    return CarbonImmutable::parse($date.' '.$time, 'Asia/Karachi')->utc();
}

/**
 * A day with several check-in / check-out pairs, in Karachi local time.
 *
 * @param  array<int, array{0: string, 1: ?string}>  $pairs
 */
function axfDay(Employee $employee, string $date, array $pairs, string $source = 'manual'): AttendanceRecord
{
    $intervals = app(AttendanceIntervalService::class);

    return AttendanceIntervalService::quietly(function () use ($employee, $date, $pairs, $source, $intervals): AttendanceRecord {
        $record = AttendanceRecord::query()->create([
            'company_id'      => $employee->company_id,
            'employee_id'     => $employee->id,
            'attendance_date' => $date,
            'check_in'        => axfUtc($date, $pairs[0][0]),
            'status'          => 'present',
            'source'          => $source,
        ]);

        foreach ($pairs as [$in, $out]) {
            $intervals->open($record, axfUtc($date, $in), $source);
            if ($out !== null) {
                $intervals->close($record, axfUtc($date, $out));
            }
        }

        return $intervals->syncSummary($record);
    });
}

function axfIntervals(AttendanceRecord $record): string
{
    return AttendanceIntervalService::describe(app(AttendanceIntervalService::class)->intervalsFor($record->fresh()), 'Asia/Karachi');
}

/** A Monday-Friday working-time calendar with one $from-$to interval a day. */
function axfCalendar(int $companyId, string $from, string $to): Calendar
{
    $calendar = Calendar::query()->create(['name' => "Office {$from}-{$to}", 'timezone' => 'Asia/Karachi', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => $companyId]);
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::query()->create(['name' => $day, 'day_of_week' => $day, 'day_period' => 'morning', 'hour_from' => $from, 'hour_to' => $to, 'calendar_id' => $calendar->id]);
    }

    return $calendar;
}

// ---------------------------------------------------------------------
// X1 / X14: typed times are the employee's local time
// ---------------------------------------------------------------------

it('X14: falls back to the attendance timezone (Asia/Karachi), not UTC, when the employee has none', function () {
    $f = geoFixture();
    $resolver = app(AttendanceScheduleResolver::class);

    expect($f['employee']->time_zone)->toBeNull()
        ->and($resolver->timezoneFor($f['employee']))->toBe('Asia/Karachi');

    config(['attendance.monthly_report.timezone' => 'Asia/Dubai']);
    expect($resolver->timezoneFor($f['employee']))->toBe('Asia/Dubai');

    $f['employee']->update(['time_zone' => 'Europe/London']);
    expect($resolver->timezoneFor($f['employee']->fresh()))->toBe('Europe/London');
});

it('X1: stores a correction typed on My Attendance in the employee timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    $record = axfDay($f['employee'], '2026-10-05', [['10:30', '18:00']]);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->mountAction('requestCorrection', ['record' => $record->id])
        // Shown in the employee's local time (the picker holds 10:30, the stored moment is 05:30 UTC).
        ->assertSet('mountedActions.0.data.requested_check_in', '2026-10-05 10:30')
        ->setActionData(['requested_check_in' => '2026-10-05 09:45', 'reason' => 'Badge reader was down'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->latest('id')->firstOrFail();
    expect($request->payload['requested']['check_in'])->toBe('2026-10-05 04:45:00')
        ->and($request->payload['requested']['check_out'])->toBe('2026-10-05 13:00:00')
        ->and($request->payload['timezone'])->toBe('Asia/Karachi');

    app(EmployeeRequestService::class)->approve($request, $hr, 'Checked the gate log');

    expect($record->fresh()->check_in->toDateTimeString())->toBe(axfUtc('2026-10-05', '09:45')->toDateTimeString());
});

it('X1: stores a missed day typed on My Attendance in the employee timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->callAction('requestMissingDay', data: [
            'attendance_date' => '2026-10-02',
            'check_in'        => '2026-10-02 10:00',
            'check_out'       => '2026-10-02 18:00',
            'reason'          => 'Phone battery died',
        ])
        ->assertHasNoActionErrors();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->latest('id')->firstOrFail();
    expect($request->payload['kind'])->toBe('attendance_missing_day')
        ->and($request->payload['requested'])->toBe(['check_in' => '2026-10-02 05:00:00', 'check_out' => '2026-10-02 13:00:00']);
});

it('X1: shows and stores the Attendance Register time change in the employee timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ViewAttendance);
    $record = axfDay($f['employee'], '2026-10-05', [['10:30', '18:00']]);
    $this->actingAs($hr);

    Livewire::test(ManageAttendanceRecords::class)
        ->mountTableAction('request_time_change', $record)
        ->assertSet('mountedActions.0.data.requested_check_in', '2026-10-05 10:30')
        ->setTableActionData(['requested_check_out' => '2026-10-05 18:45'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->latest('id')->firstOrFail();
    expect($request->payload['requested']['check_out'])->toBe('2026-10-05 13:45:00')
        ->and($request->payload['requested']['check_in'])->toBe('2026-10-05 05:30:00');
});

// ---------------------------------------------------------------------
// X2: a correction edits only the interval it targets
// ---------------------------------------------------------------------

it('X2: an approved check-in correction moves only the first interval and keeps the break', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    $record = axfDay($f['employee'], '2026-10-05', [['10:00', '13:00'], ['14:00', '18:00']]);
    expect((float) $record->worked_hours)->toBe(7.0);

    $requests = app(EmployeeRequestService::class);
    $request = $requests->requestAttendanceTimeChange($record, $f['user'], ['check_in' => axfUtc('2026-10-05', '09:30')], 'Badge reader was down');
    $requests->approve($request, $hr, 'Gate log confirms');

    expect(axfIntervals($record))->toBe('09:30–13:00, 14:00–18:00')
        ->and((float) $record->fresh()->worked_hours)->toBe(7.5)
        ->and($record->fresh()->check_out->toDateTimeString())->toBe(axfUtc('2026-10-05', '18:00')->toDateTimeString());

    $request = $requests->requestAttendanceTimeChange($record->fresh(), $f['user'], ['check_out' => axfUtc('2026-10-05', '18:30')], 'Left later than recorded');
    $requests->approve($request, $hr, 'Gate log confirms');

    expect(axfIntervals($record))->toBe('09:30–13:00, 14:00–18:30')
        ->and((float) $record->fresh()->worked_hours)->toBe(8.0)
        ->and($record->fresh()->status)->toBe('present');
});

it('X2: an HR edit of the check-out keeps the earlier pairs; a missed-day approval replaces the whole day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);

    $record = axfDay($f['employee'], '2026-10-05', [['10:00', '13:00'], ['14:00', null]]);
    $record->update(['check_out' => axfUtc('2026-10-05', '17:00')]);
    expect(axfIntervals($record))->toBe('10:00–13:00, 14:00–17:00')
        ->and((float) $record->fresh()->worked_hours)->toBe(6.0);

    // A missed-day request stands for the whole day: a record that appeared meanwhile is replaced.
    $requests = app(EmployeeRequestService::class);
    $missed = $requests->requestMissingAttendance($f['employee'], $f['user'], '2026-10-02', ['check_in' => axfUtc('2026-10-02', '10:00'), 'check_out' => axfUtc('2026-10-02', '18:00')], 'Phone died');
    $meanwhile = axfDay($f['employee'], '2026-10-02', [['10:00', '12:00'], ['15:00', '16:00']]);
    $requests->approve($missed, $hr, 'ok');

    expect(axfIntervals($meanwhile))->toBe('10:00–18:00')
        ->and($meanwhile->intervals()->whereNotNull('voided_at')->count())->toBe(2);

    $this->actingAs($f['user']);
    Livewire::test(MyAttendance::class)
        ->mountAction('requestMissingDay')
        ->assertMountedActionModalSee('records the whole day as one check-in / check-out pair');
});

// ---------------------------------------------------------------------
// X3 / D3: half-day classification
// ---------------------------------------------------------------------

it('X3: labels a short GPS day half_day and a full one present', function () {
    $f = geoFixture();
    $service = app(GeofencedAttendanceService::class);

    $this->travelTo(axfUtc('2026-10-05', '10:00'));
    $service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(axfUtc('2026-10-05', '13:00'));
    $service->checkOut($f['user'], geoEvidence(), geoRequest());

    $short = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->where('attendance_date', '2026-10-05')->firstOrFail();
    expect($short->status)->toBe('half_day');

    $this->travelTo(axfUtc('2026-10-06', '10:00'));
    $service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(axfUtc('2026-10-06', '17:45'));
    $service->checkOut($f['user'], geoEvidence(), geoRequest());

    $full = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->where('attendance_date', '2026-10-06')->firstOrFail();
    expect($full->status)->toBe('present');
});

it('X3: classifies worked time against the day\'s required minutes minus the tolerance', function () {
    $f = geoFixture();
    $classifier = app(AttendanceStatusClassifier::class);

    // Monday 2026-10-05, company default 480 required, tolerance 30.
    expect($classifier->classify($f['employee'], '2026-10-05', 0, false))->toBe('absent')
        ->and($classifier->classify($f['employee'], '2026-10-05', 180, false))->toBe('half_day')
        ->and($classifier->classify($f['employee'], '2026-10-05', 449, false))->toBe('half_day')
        ->and($classifier->classify($f['employee'], '2026-10-05', 450, false))->toBe('present')
        ->and($classifier->classify($f['employee'], '2026-10-05', 450, true))->toBe('remote')
        // A weekend has nothing required.
        ->and($classifier->classify($f['employee'], '2026-10-03', 60, false))->toBe('present');

    WorkSchedule::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'start_time' => '10:00', 'end_time' => '16:00', 'required_minutes' => 360, 'working_days' => [1, 2, 3, 4, 5], 'flexible_minutes' => 0, 'effective_from' => '2026-01-01']);
    expect($classifier->classify($f['employee']->fresh(), '2026-10-05', 340, false))->toBe('present');
});

// ---------------------------------------------------------------------
// X4 / D13: one schedule rule for the record, Today's page and the report
// ---------------------------------------------------------------------

it('X4: the record, Today\'s attendance and the monthly report measure lateness against the same schedule', function () {
    $f = geoFixture();
    [$calendarUser, $calendarEmployee] = geoEmployee($f['company'], $f['location']);
    $calendarEmployee->update(['calendar_id' => axfCalendar($f['company']->id, '09:00', '17:00')->id]);
    $service = app(GeofencedAttendanceService::class);

    // No schedule, no calendar: the company default 10:00-18:00.
    $this->travelTo(axfUtc('2026-10-05', '10:20'));
    $service->checkIn($f['user'], geoEvidence(), geoRequest());
    // The employee's own calendar 09:00-17:00.
    $this->travelTo(axfUtc('2026-10-05', '09:25'));
    $service->checkIn($calendarUser, geoEvidence(), geoRequest());
    $this->travelTo(axfUtc('2026-10-05', '11:00'));

    $today = app(TodayAttendance::class)->forWholeCompany($f['company']->id, '2026-10-05')['rows'];
    $report = app(MonthlyAttendanceReport::class);
    $reportRow = fn (Employee $employee): array => collect($report->forEmployee($employee->fresh(), $f['company']->id, '2026-10')['rows'])->keyBy('date')['2026-10-05'];

    foreach ([[$f['employee'], 20, '10:00'], [$calendarEmployee, 25, '09:00']] as [$employee, $late, $start]) {
        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->where('attendance_date', '2026-10-05')->firstOrFail();

        expect($record->scheduled_start->toDateTimeString())->toBe(axfUtc('2026-10-05', $start)->toDateTimeString())
            ->and($record->late_minutes)->toBe($late)
            ->and($today[$employee->id]['late_minutes'])->toBe($late)
            ->and($reportRow($employee)['late_minutes'])->toBe($late);
    }

    expect(app(AttendanceScheduleResolver::class)->scheduleFor($calendarEmployee->fresh(), '2026-10-05')->source)->toBe('calendar')
        ->and(app(AttendanceScheduleResolver::class)->scheduleFor($f['employee'], '2026-10-05')->isDefault())->toBeTrue();
});

// ---------------------------------------------------------------------
// X5: notices without a line manager go to the company HR approvers
// ---------------------------------------------------------------------

it('X5: sends a flagged check-in of an employee without a line manager to the company HR approvers only', function () {
    $this->travelTo(axfUtc('2026-10-05', '10:00'));
    $f = geoFixture();
    $hrApprover = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    [$teamLead] = geoEmployee($f['company'], $f['location']);
    geoGrant($teamLead, HrPermissions::ManageAttendance, HrPermissions::ReviewAttendanceVerifications);

    $result = app(GeofencedAttendanceService::class)->checkIn($f['user'], geoEvidence(['accuracy' => 100.0]), geoRequest());
    expect($result->accepted)->toBeTrue();

    $titles = fn ($user) => $user->notifications()->get()->map(fn ($notification): string => (string) ($notification->data['title'] ?? ''));

    expect($titles($hrApprover))->toContain('Attendance to check: Check-in flagged')
        ->and($titles($teamLead))->not->toContain('Attendance to check: Check-in flagged')
        ->and($titles($f['user']))->not->toContain('Attendance to check: Check-in flagged');
});

// ---------------------------------------------------------------------
// D11: missed-day schedule snapshot and status after a correction
// ---------------------------------------------------------------------

it('D11: gives an approved missed day its scheduled times and a status from the worked time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    $requests = app(EmployeeRequestService::class);

    $missed = $requests->requestMissingAttendance($f['employee'], $f['user'], '2026-10-02', ['check_in' => axfUtc('2026-10-02', '10:30'), 'check_out' => axfUtc('2026-10-02', '13:30')], 'Phone died');
    $requests->approve($missed, $hr, 'ok');

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->where('attendance_date', '2026-10-02')->firstOrFail();
    expect($record->scheduled_start->toDateTimeString())->toBe(axfUtc('2026-10-02', '10:00')->toDateTimeString())
        ->and($record->scheduled_end->toDateTimeString())->toBe(axfUtc('2026-10-02', '18:00')->toDateTimeString())
        ->and($record->late_minutes)->toBe(30)
        ->and($record->status)->toBe('half_day');

    // A correction that completes the day makes it present again.
    $change = $requests->requestAttendanceTimeChange($record, $f['user'], ['check_out' => axfUtc('2026-10-02', '18:15')], 'Stayed on');
    $requests->approve($change, $hr, 'ok');

    expect($record->fresh()->status)->toBe('present');
});

// ---------------------------------------------------------------------
// X12: the employee Attendance tab
// ---------------------------------------------------------------------

it('X12: the Attendance tab month filter shows the chosen month on the 31st', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00', 'UTC'));
    $f = geoFixture();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ViewAttendance);
    $september = axfDay($f['employee'], '2026-09-15', [['10:00', '18:00']]);
    $october = axfDay($f['employee'], '2026-10-15', [['10:00', '18:00']]);
    $this->actingAs($hr);

    Livewire::test(AttendanceRecordsRelationManager::class, ['ownerRecord' => $f['employee'], 'pageClass' => ViewEmployee::class])
        ->filterTable('month', '2026-09')
        ->assertCanSeeTableRecords([$september])
        ->assertCanNotSeeTableRecords([$october]);
});

it('X12: the attendance scorecard counts working days from the schedule and skips public holidays', function () {
    $f = geoFixture();
    CalendarLeave::query()->create(['name' => 'Founders Day', 'company_id' => $f['company']->id, 'time_type' => 'leave', 'date_from' => '2026-10-05 00:00:00', 'date_to' => '2026-10-05 23:59:59']);
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05', 'status' => 'present', 'source' => 'manual']);
    AttendanceRecord::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-10', 'status' => 'present', 'source' => 'manual']);

    // October 2026: 22 weekdays, one of them a holiday; the Saturday record is off the schedule.
    $summary = AttendanceRecordsRelationManager::getMonthlySummary($f['employee'], '2026-10');
    expect($summary['working_days'])->toBe(21)->and($summary['present'])->toBe(0);

    // A Monday-Saturday work schedule makes the Saturdays working days.
    WorkSchedule::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'start_time' => '10:00', 'end_time' => '18:00', 'required_minutes' => 480, 'working_days' => [1, 2, 3, 4, 5, 6], 'flexible_minutes' => 0, 'effective_from' => '2026-01-01']);
    $summary = AttendanceRecordsRelationManager::getMonthlySummary($f['employee']->fresh(), '2026-10');
    expect($summary['working_days'])->toBe(26)->and($summary['present'])->toBe(1);
});

// ---------------------------------------------------------------------
// X11: shift commands
// ---------------------------------------------------------------------

it('X11: schedules hr:auto-complete-shifts, which closes nothing under the default policy', function () {
    expect(config('hr_attendance_geofence.auto_close_policy'))->toBe('none');
    $this->artisan('schedule:list')->expectsOutputToContain('hr:auto-complete-shifts')->assertSuccessful();

    $f = geoFixture();
    $this->travelTo(axfUtc('2026-10-05', '10:00'));
    app(GeofencedAttendanceService::class)->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(axfUtc('2026-10-05', '20:00'));

    $this->artisan('hr:auto-complete-shifts')->assertSuccessful();

    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole()->check_out)->toBeNull();
});

it('X11: hr:notify-shift-completion uses the schedule\'s required minutes, not a fixed 8 hours', function () {
    $f = geoFixture();
    [$partTimeUser, $partTime] = geoEmployee($f['company'], $f['location']);
    WorkSchedule::query()->create(['company_id' => $f['company']->id, 'employee_id' => $partTime->id, 'start_time' => '10:00', 'end_time' => '18:00', 'required_minutes' => 360, 'working_days' => [1, 2, 3, 4, 5], 'flexible_minutes' => 0, 'effective_from' => '2026-01-01']);
    axfDay($f['employee'], '2026-10-05', [['10:00', null]], 'gps');
    axfDay($partTime, '2026-10-05', [['10:00', null]], 'gps');

    $this->travelTo(axfUtc('2026-10-05', '16:05'));
    $this->artisan('hr:notify-shift-completion')->assertSuccessful();

    $completed = fn ($user): bool => $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['title'] ?? '') === 'Working hours complete');
    expect($completed($partTimeUser))->toBeTrue()
        ->and($completed($f['user']))->toBeFalse();
});

// ---------------------------------------------------------------------
// overtime_hours: computed from approved overtime
// ---------------------------------------------------------------------

it('fills overtime_hours from approved overtime and reports approved overtime in HR analytics', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $f = geoFixture();
    $manager = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ManageEmployeeRequests, HrPermissions::ApproveLeave);
    $record = axfDay($f['employee'], '2026-10-05', [['10:00', '20:00']]);
    $other = axfDay($f['employee'], '2026-10-01', [['10:00', '18:00']]);
    // A value typed by hand before the change stays on its day, and is not reported as approved overtime.
    $other->forceFill(['overtime_hours' => 3])->save();

    $overtime = app(OvertimeService::class);
    $request = $overtime->request($f['employee'], $f['user'], '2026-10-05', 90, 'Month-end close');
    while ($request->fresh()->status === 'pending_approval') {
        $overtime->approve($request->fresh(), $manager);
    }

    expect($request->fresh()->status)->toBe('approved')
        ->and((float) $record->fresh()->overtime_hours)->toBe(1.5)
        ->and((float) $other->fresh()->overtime_hours)->toBe(3.0)
        ->and(app(HrAnalyticsService::class)->summary($f['company']->id, '2026-10-01', '2026-10-31')['attendance_overtime_hours'])->toBe(1.5)
        ->and(app(OvertimeService::class)->backfillAttendanceOvertimeHours())->toMatchArray(['approved_days' => 1, 'filled' => 0, 'already_in_sync' => 1, 'kept_manual_value' => 0]);

    // The register form no longer accepts a typed value.
    $this->actingAs($manager);
    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('edit', $other, data: ['overtime_hours' => 9, 'notes' => 'checked'])
        ->assertHasNoTableActionErrors();
    expect((float) $other->fresh()->overtime_hours)->toBe(3.0)
        ->and($other->fresh()->notes)->toBe('checked');
});

// ---------------------------------------------------------------------
// A later check-in keeps the day's review outcome
// ---------------------------------------------------------------------

it('keeps a rejected or HR-corrected day out of "verified" when the employee checks in again', function () {
    $f = geoFixture();
    $service = app(GeofencedAttendanceService::class);

    foreach (['2026-10-05' => Status::ReviewRejected, '2026-10-06' => Status::Overridden] as $date => $outcome) {
        $this->travelTo(axfUtc($date, '10:00'));
        $service->checkIn($f['user'], geoEvidence(), geoRequest());
        $this->travelTo(axfUtc($date, '12:00'));
        $service->checkOut($f['user'], geoEvidence(), geoRequest());

        $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->where('attendance_date', $date)->firstOrFail();
        $record->newQuery()->whereKey($record->id)->update(['verification_status' => $outcome->value]);

        $this->travelTo(axfUtc($date, '13:00'));
        $result = $service->checkIn($f['user'], geoEvidence(), geoRequest());

        expect($result->accepted)->toBeTrue()
            ->and($record->fresh()->verification_status)->toBe($outcome->value);
    }
});
