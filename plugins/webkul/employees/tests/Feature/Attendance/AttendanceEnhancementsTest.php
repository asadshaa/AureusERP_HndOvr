<?php

require_once __DIR__.'/GeoTestHelpers.php';

use App\Filament\Widgets\TodayAttendanceRollCallWidget;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Webkul\Accounting\Filament\Widgets\JournalChartsWidget;
use Webkul\Employee\Enums\AttendanceVerificationAction as Action;
use Webkul\Employee\Enums\AttendanceVerificationMethod as Method;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Enums\AttendanceVerificationStatus as Status;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Filament\Widgets\ApplicantChartWidget;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Services\ApprovalEngine;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Cache::flush();
});

it('notifies employees when they have reached 8 hours of work without checking out', function () {
    $f = geoFixture();

    // Check in at 09:00 UTC
    $checkInTime = CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC');
    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => '2026-10-02',
        'check_in'        => $checkInTime,
        'status'          => 'present',
        'source'          => 'gps',
    ]);

    // Fast forward to 17:01 UTC (8 hours 1 minute later)
    $this->travelTo(CarbonImmutable::parse('2026-10-02 17:01:00', 'UTC'));

    $this->artisan('hr:notify-shift-completion')
        ->expectsOutputToContain('Notified 1 employee(s)')
        ->assertSuccessful();

    // Verify database notification received by the employee user
    $notification = $f['user']->notifications()->sole();
    expect($notification->data['title'])->toContain('Working hours complete')
        ->and($notification->data['body'])->toContain('complete');

    // Run again - verify idempotency via cache
    $this->artisan('hr:notify-shift-completion')
        ->expectsOutputToContain('Notified 0 employee(s)')
        ->assertSuccessful();
});

it('provides bulk approval for needs-review attendance records', function () {
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ReviewAttendanceVerifications);
    $this->actingAs($hrUser);

    // Create 2 records needing review
    $record1 = AttendanceRecord::query()->create([
        'company_id'          => $f['company']->id,
        'employee_id'         => $f['employee']->id,
        'attendance_date'     => '2026-10-01',
        'check_in'            => '2026-10-01 09:00:00',
        'check_out'           => '2026-10-01 17:00:00',
        'status'              => 'present',
        'source'              => 'gps',
        'verification_status' => Status::NeedsReview->value,
    ]);

    AttendanceVerification::query()->create([
        'company_id'           => $f['company']->id,
        'employee_id'          => $f['employee']->id,
        'attendance_record_id' => $record1->id,
        'action'               => Action::CheckIn,
        'method'               => Method::Gps,
        'result'               => Result::NeedsReview,
        'accepted'             => true,
        'review_status'        => 'pending',
        'server_recorded_at'   => now(),
    ]);

    $record2 = AttendanceRecord::query()->create([
        'company_id'          => $f['company']->id,
        'employee_id'         => $f['employee']->id,
        'attendance_date'     => '2026-10-02',
        'check_in'            => '2026-10-02 09:00:00',
        'check_out'           => '2026-10-02 17:00:00',
        'status'              => 'present',
        'source'              => 'gps',
        'verification_status' => Status::NeedsReview->value,
    ]);

    AttendanceVerification::query()->create([
        'company_id'           => $f['company']->id,
        'employee_id'          => $f['employee']->id,
        'attendance_record_id' => $record2->id,
        'action'               => Action::CheckIn,
        'method'               => Method::Gps,
        'result'               => Result::NeedsReview,
        'accepted'             => true,
        'review_status'        => 'pending',
        'server_recorded_at'   => now(),
    ]);

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableBulkAction('bulk_approve_verifications', [$record1->id, $record2->id], [
            'note' => 'Approved all after verification',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect($record1->refresh()->verification_status)->toBe(Status::Reviewed->value)
        ->and($record2->refresh()->verification_status)->toBe(Status::Reviewed->value);
});

it('renders today roll call widget with accurate headcount and attendance metrics', function () {
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    $this->actingAs($hrUser);

    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));

    AttendanceRecord::query()->create([
        'company_id'          => $f['company']->id,
        'employee_id'         => $f['employee']->id,
        'attendance_date'     => '2026-10-02',
        'check_in'            => '2026-10-02 10:30:00',
        'late_minutes'        => 30,
        'status'              => 'present',
        'source'              => 'gps',
        'verification_status' => Status::Verified->value,
    ]);

    Livewire::test(TodayAttendanceRollCallWidget::class)
        ->assertSee("Today's Roll Call", false)
        ->assertSee('Total Staff')
        ->assertSee('Checked In')
        ->assertSee('Late Arrivals');
});

it('calculates monthly calendar attendance data on My Attendance', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));

    AttendanceRecord::query()->create([
        'company_id'          => $f['company']->id,
        'employee_id'         => $f['employee']->id,
        'attendance_date'     => '2026-10-01',
        'check_in'            => '2026-10-01 09:00:00',
        'check_out'           => '2026-10-01 17:00:00',
        'worked_hours'        => 8.0,
        'status'              => 'present',
        'source'              => 'gps',
        'verification_status' => Status::Verified->value,
    ]);

    $component = Livewire::test(MyAttendance::class);
    $component->assertSee('October 2026 Attendance')
        ->assertSee('Days Present')
        ->assertSee('Total Hours');

    $calendarData = $component->instance()->getMonthlyCalendarData();
    expect($calendarData['stats']['daysPresent'])->toBe(1)
        ->and($calendarData['stats']['totalHours'])->toBe(8.0);
});

it('enforces role-based widget visibility between HR, accounting, and employee dashboards', function () {
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance);
    $employeeUser = $f['user'];

    // HR user should see Roll Call widget but NOT Accounting Journal Charts
    $this->actingAs($hrUser);
    expect(TodayAttendanceRollCallWidget::canView())->toBeTrue()
        ->and(JournalChartsWidget::canView())->toBeFalse();

    // Regular employee should NOT see Roll Call, Accounting Charts, or Applicant Charts
    $this->actingAs($employeeUser);
    expect(TodayAttendanceRollCallWidget::canView())->toBeFalse()
        ->and(JournalChartsWidget::canView())->toBeFalse()
        ->and(ApplicantChartWidget::canView())->toBeFalse();

    // Admin should see both Roll Call and Accounting Charts
    $admin = User::where('email', 'raza.afzal@truckitin.com')->first();
    if ($admin) {
        $this->actingAs($admin);
        expect(TodayAttendanceRollCallWidget::canView())->toBeTrue()
            ->and(JournalChartsWidget::canView())->toBeTrue();
    }
});

it('routes requests to HR when an employee has no line manager assigned', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
    $f = geoFixture();
    // Employee has no line manager ($f['employee']->parent_id is null)
    expect($f['employee']->parent_id)->toBeNull();

    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords);
    [$outsiderUser, $outsider] = geoEmployee($f['company'], $f['location']);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => '2026-10-04',
        'check_in'        => '2026-10-04 04:00:00',
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $requests = app(EmployeeRequestService::class);
    $engine = app(ApprovalEngine::class);

    // 1. Time change request routes to HR
    $timeChange = $requests->requestAttendanceTimeChange($record, $f['user'], ['check_in' => '2026-10-04 03:30:00'], 'Badge reader down');
    expect($timeChange->status)->toBe('pending_approval')
        ->and($engine->describeCurrentApprover($timeChange->approvalRequest))
        ->toBe('Forwarded to HR (no line manager assigned).');

    // Requester cannot approve own request, outsider cannot approve, HR can approve
    expect($engine->canAct($timeChange->approvalRequest, $f['user']))->toBeFalse()
        ->and($engine->canAct($timeChange->approvalRequest, $outsiderUser))->toBeFalse()
        ->and($engine->canAct($timeChange->approvalRequest, $hrUser))->toBeTrue();

    $requests->approve($timeChange, $hrUser, 'Approved by HR');
    expect($timeChange->fresh()->status)->toBe('approved')
        ->and($record->fresh()->check_in->toTimeString())->toBe('03:30:00');

    // 2. Missed attendance day routes to HR
    $missing = $requests->requestMissingAttendance(
        $f['employee'],
        $f['user'],
        '2026-10-03',
        ['check_in' => '2026-10-03 04:00:00', 'check_out' => '2026-10-03 12:00:00'],
        'Phone battery died',
    );
    expect($missing->status)->toBe('pending_approval')
        ->and($engine->describeCurrentApprover($missing->approvalRequest))
        ->toBe('Forwarded to HR (no line manager assigned).');

    expect($engine->canAct($missing->approvalRequest, $f['user']))->toBeFalse()
        ->and($engine->canAct($missing->approvalRequest, $outsiderUser))->toBeFalse()
        ->and($engine->canAct($missing->approvalRequest, $hrUser))->toBeTrue();

    $requests->approve($missing, $hrUser, 'Approved by HR');
    expect($missing->fresh()->status)->toBe('approved');
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->where('attendance_date', '2026-10-03')->exists())->toBeTrue();
});

it('sends complete notification with employee, what, day of week, date, times, and reason to HR/manager', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => '2026-10-04',
        'check_in'        => '2026-10-04 04:00:00',
        'check_out'       => '2026-10-04 12:00:00',
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $requests = app(EmployeeRequestService::class);

    // 1. Time change request submitted
    $timeChange = $requests->requestAttendanceTimeChange(
        $record,
        $f['user'],
        ['check_in' => '2026-10-04 03:30:00', 'check_out' => '2026-10-04 12:30:00'],
        'Traffic delayed checkout correction',
    );

    $notification = $hrUser->notifications()->latest()->first();
    expect($notification)->not->toBeNull();
    $body = $notification->data['body'];
    $title = $notification->data['title'];

    // Who sent a request
    expect($body)->toContain($f['employee']->name)
        // Of what
        ->and($body)->toContain('Attendance Time Change')
        // Of which day
        ->and($body)->toContain('Sunday')
        // On which date
        ->and($body)->toContain('04 Oct 2026')
        // Times
        ->and($body)->toContain('In 04:00→03:30')
        ->and($body)->toContain('Out 12:00→12:30')
        // Reason
        ->and($body)->toContain('Traffic delayed checkout correction')
        // Routing note
        ->and($body)->toContain('(Sent to HR because no line manager is set.)');

    // 2. Test ManageEmployeeRequests Livewire table renders all details
    $this->actingAs($hrUser);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertSee($f['employee']->name)
        ->assertSee('04 Oct 2026 (Sunday)')
        ->assertSee('Traffic delayed checkout correction');

    // 3. Test ListApprovalRequests Livewire table renders all details
    geoGrant($hrUser, 'view_any_support_approval::request', 'view_support_approval::request');
    Livewire::test(ListApprovalRequests::class)
        ->assertSee($f['employee']->name)
        ->assertSee('04 Oct 2026 (Sunday)')
        ->assertSee('In: 04:00 → 03:30');
});

it('formats attendance request from simple date and time pickers without re-selecting date', function () {
    $f = geoFixture();

    // 1. Test formatAttendancePayload synthesizes full datetimes from date and time pickers
    $formData = [
        'payload' => [
            'attendance_date'          => '2026-10-06',
            'requested_check_in_time'  => '08:45',
            'requested_check_out_time' => '17:15',
        ],
    ];

    $formatted = EmployeeRequestResource::formatAttendancePayload($formData);
    expect($formatted['payload']['day_of_week'])->toBe('Tuesday')
        ->and($formatted['payload']['formatted_date'])->toBe('06 Oct 2026')
        ->and($formatted['payload']['requested']['check_in'])->toBe('2026-10-06 08:45:00')
        ->and($formatted['payload']['requested']['check_out'])->toBe('2026-10-06 17:15:00');

    // 2. Overnight shift test (check-out time < check-in time rolls to next day)
    $overnightData = [
        'payload' => [
            'attendance_date'          => '2026-10-06',
            'requested_check_in_time'  => '22:00',
            'requested_check_out_time' => '06:00',
        ],
    ];
    $overnightFormatted = EmployeeRequestResource::formatAttendancePayload($overnightData);
    expect($overnightFormatted['payload']['requested']['check_in'])->toBe('2026-10-06 22:00:00')
        ->and($overnightFormatted['payload']['requested']['check_out'])->toBe('2026-10-07 06:00:00');

    // 3. Test saving an EmployeeRequest with time picker payload auto-populates datetimes via model saving hook
    $requestType = EmployeeRequestType::firstOrCreate([
        'company_id' => $f['company']->id,
        'code'       => 'attendance_time_change',
    ], [
        'name'      => 'Attendance Time Change',
        'category'  => 'attendance_correction',
        'is_active' => true,
    ]);

    $req = EmployeeRequest::create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $requestType->id,
        'title'           => 'Correction for Tuesday',
        'payload'         => [
            'attendance_date'          => '2026-10-06',
            'requested_check_in_time'  => '09:15',
            'requested_check_out_time' => '18:30',
        ],
    ]);

    expect($req->fresh()->payload['requested']['check_in'])->toBe('2026-10-06 09:15:00')
        ->and($req->fresh()->payload['requested']['check_out'])->toBe('2026-10-06 18:30:00');
});
