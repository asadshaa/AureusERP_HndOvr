<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);
use Webkul\Employee\Enums\AttendanceVerificationAction as Action;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Calendar;
use Webkul\Employee\Models\EmployeeWorkLocationAssignment;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Support\Models\CalendarAttendance;

beforeEach(function () {
    $this->service = app(GeofencedAttendanceService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

function geoRecords($employee)
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->orderBy('id')->get();
}

// ---------------------------------------------------------------------
// Check-in
// ---------------------------------------------------------------------

it('records a verified check-in with server time, evidence and a two-way link', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::Verified)
        ->and($result->message)->toContain('Checked in at 05:00 AM');

    $record = geoRecords($f['employee'])->sole();
    expect($record->source)->toBe('gps')->and($record->status)->toBe('present')
        ->and($record->verification_status)->toBe('verified')
        ->and($record->check_in->toDateTimeString())->toBe('2026-10-05 05:00:00')
        ->and($record->attendance_date->toDateString())->toBe('2026-10-05');

    $verification = AttendanceVerification::query()->sole();
    expect($verification->attendance_record_id)->toBe($record->id)
        ->and($record->check_in_verification_id)->toBe($verification->id)
        ->and($verification->accepted)->toBeTrue()
        ->and($verification->action)->toBe(Action::CheckIn)
        ->and((float) $verification->distance_meters)->toBe(0.0)
        ->and($verification->geofence_snapshot['name'])->toBe('HQ')
        ->and($verification->ip_address)->toBe('10.0.0.7')
        ->and((float) $verification->latitude)->toBe(GEO_LAT);
});

it('uses the server clock, never the browser-reported time, for the check-in moment', function () {
    $f = geoFixture();

    $this->service->checkIn($f['user'], geoEvidence(['capturedAt' => CarbonImmutable::now('UTC')->subSeconds(90)]), geoRequest());

    expect(geoRecords($f['employee'])->sole()->check_in->toDateTimeString())->toBe('2026-10-05 05:00:00');
});

it('computes late minutes from the employee calendar in the employee timezone', function () {
    $f = geoFixture(['time_zone' => 'Asia/Karachi']);
    $calendar = Calendar::query()->create(['name' => 'Office', 'timezone' => 'Asia/Karachi', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => $f['company']->id]);
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        CalendarAttendance::query()->create(['name' => $day, 'day_of_week' => $day, 'day_period' => 'morning', 'hour_from' => 9, 'hour_to' => 17, 'calendar_id' => $calendar->id]);
    }
    $f['employee']->update(['calendar_id' => $calendar->id]);

    // 04:15 UTC = 09:15 in Karachi: 15 minutes after a 09:00 start.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 04:15:00', 'UTC'));
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $record = geoRecords($f['employee'])->sole();
    expect($record->late_minutes)->toBe(15)
        ->and($record->scheduled_start->toDateTimeString())->toBe('2026-10-05 04:00:00')
        ->and($record->scheduled_end->toDateTimeString())->toBe('2026-10-05 12:00:00');
});

it('rejects a check-in outside the geofence, keeps the evidence and creates no attendance', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(geoOutside()), geoRequest());

    expect($result->accepted)->toBeFalse()->and($result->result)->toBe(Result::OutsideGeofence)
        ->and($result->message)->not->toContain('1');

    expect(geoRecords($f['employee']))->toHaveCount(0);
    $verification = AttendanceVerification::query()->sole();
    expect($verification->accepted)->toBeFalse()->and($verification->result)->toBe(Result::OutsideGeofence)
        ->and((float) $verification->distance_meters)->toBeGreaterThan(1000)
        ->and($verification->failure_reason)->toBe('outside_geofence');
});

it('accepts a mediocre-accuracy check-in inside the fence and queues it for review', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(['accuracy' => 80.0]), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::NeedsReview);
    $record = geoRecords($f['employee'])->sole();
    expect($record->verification_status)->toBe('needs_review');
    expect(AttendanceVerification::query()->sole()->review_status)->toBe('pending');
});

it('rejects an extremely inaccurate check-in and creates no attendance', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(['accuracy' => 500.0]), geoRequest());

    expect($result->result)->toBe(Result::LowAccuracy)->and($result->accepted)->toBeFalse();
    expect(geoRecords($f['employee']))->toHaveCount(0);
});

it('rejects invalid coordinates without storing them', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(['latitude' => 95.0]), geoRequest());

    expect($result->result)->toBe(Result::InvalidCoordinates);
    $verification = AttendanceVerification::query()->sole();
    expect($verification->latitude)->toBeNull()->and($verification->longitude)->toBeNull();
});

it('records a denied-permission check-in without coordinates and creates no attendance', function (string $code, Result $expected) {
    $f = geoFixture();

    $result = $this->service->recordClientFailure($f['user'], Action::CheckIn, $code, (string) Str::uuid(), geoRequest());

    expect($result->accepted)->toBeFalse()->and($result->result)->toBe($expected);
    expect(geoRecords($f['employee']))->toHaveCount(0);
    $verification = AttendanceVerification::query()->sole();
    expect($verification->latitude)->toBeNull()->and($verification->metadata['client_error_code'])->toBe($code);
})->with([
    'denied'      => ['permission_denied', Result::PermissionDenied],
    'unavailable' => ['position_unavailable', Result::LocationUnavailable],
    'timeout'     => ['timeout', Result::LocationTimeout],
    'insecure'    => ['insecure_context', Result::InsecureContext],
    'unsupported' => ['unsupported', Result::LocationUnavailable],
]);

it('refuses an unknown client error code', function () {
    $f = geoFixture();

    expect(fn () => $this->service->recordClientFailure($f['user'], Action::CheckIn, 'made_up', null, geoRequest()))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// Check-out
// ---------------------------------------------------------------------

it('records a verified check-out and computes worked hours through the existing hook', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:30:00', 'UTC'));
    $result = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->message)->toContain('Checked out at 01:30 PM');
    $record = geoRecords($f['employee'])->sole();
    expect((float) $record->worked_hours)->toBe(8.5)
        ->and($record->check_out_verification_id)->not->toBeNull()
        ->and($record->verification_status)->toBe('verified');
    expect(AttendanceVerification::query()->count())->toBe(2);
});

it('does not lose a check-out to a denied location permission, it flags it for review', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $result = $this->service->recordClientFailure($f['user'], Action::CheckOut, 'permission_denied', (string) Str::uuid(), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::NeedsReview);
    $record = geoRecords($f['employee'])->sole();
    expect($record->check_out)->not->toBeNull()->and($record->verification_status)->toBe('needs_review');
    $last = AttendanceVerification::query()->latest('id')->first();
    expect($last->flags)->toContain('location_unavailable')->and($last->review_status)->toBe('pending');
});

it('rejects a denied-permission check-out when the policy is reject', function () {
    config(['hr_attendance_geofence.checkout_without_location' => 'reject']);
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $result = $this->service->recordClientFailure($f['user'], Action::CheckOut, 'permission_denied', null, geoRequest());

    expect($result->accepted)->toBeFalse()->and(geoRecords($f['employee'])->sole()->check_out)->toBeNull();
});

it('flags a check-out from outside the fence for review by default', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $result = $this->service->checkOut($f['user'], geoEvidence(geoOutside()), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::NeedsReview);
    expect(geoRecords($f['employee'])->sole()->verification_status)->toBe('needs_review');
});

it('refuses a check-out when the employee is not checked in', function () {
    $f = geoFixture();

    expect($this->service->checkOut($f['user'], geoEvidence(), geoRequest())->result)->toBe(Result::NotCheckedIn);
});

it('keeps a check-out that arrives after midnight on the record of the day the shift started', function () {
    $f = geoFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 22:00:00', 'UTC'));
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $this->service->checkOut($f['user'], geoEvidence(), geoRequest());

    $record = geoRecords($f['employee'])->sole();
    expect($record->attendance_date->toDateString())->toBe('2026-10-05')
        ->and((float) $record->worked_hours)->toBe(8.0);
});

// ---------------------------------------------------------------------
// Duplicates
// ---------------------------------------------------------------------

it('never creates a second record for a duplicate check-in', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $second = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($second->result)->toBe(Result::OpenShiftExists)->and($second->accepted)->toBeFalse();
    expect(geoRecords($f['employee']))->toHaveCount(1);
});

// Business rule of 2026-10-28: several check-in / check-out pairs per day. A check-in
// after the day's shift was closed opens a new interval on the same day (it used to be
// refused as "already checked in").
it('opens a second interval on the same day once the shift has been closed', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $this->service->checkOut($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 14:00:00', 'UTC'));
    $again = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($again->accepted)->toBeTrue();
    $record = geoRecords($f['employee'])->sole();
    expect($record->check_out)->toBeNull()
        ->and($record->intervals()->whereNull('voided_at')->count())->toBe(2);
});

it('reports an already-checked-out day on a duplicate check-out', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $this->service->checkOut($f['user'], geoEvidence(), geoRequest());

    $again = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());

    expect($again->result)->toBe(Result::AlreadyCheckedOut)->and($again->accepted)->toBeFalse();
});

it('is idempotent for a retried request id: one verification, the same answer', function () {
    $f = geoFixture();
    $evidence = geoEvidence();

    $first = $this->service->checkIn($f['user'], $evidence, geoRequest());
    $replay = $this->service->checkIn($f['user'], $evidence, geoRequest());

    expect($replay->result)->toBe($first->result)->and($replay->accepted)->toBeTrue();
    expect(AttendanceVerification::query()->count())->toBe(1)->and(geoRecords($f['employee']))->toHaveCount(1);
});

it('blocks a new check-in while a shift younger than the maximum shift length is open', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'check_in'   => '2026-10-05 02:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::OpenShiftExists)->and($result->message)->toContain('since');
});

it('treats a shift open longer than the maximum as forgotten and lets a fresh check-in through', function () {
    $f = geoFixture();
    $stale = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-03',
        'check_in'   => '2026-10-03 05:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->accepted)->toBeTrue();
    expect(geoRecords($f['employee']))->toHaveCount(2)
        ->and($stale->fresh()->check_out)->toBeNull();
});

it('blocks check-in on a day already recorded as leave', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'status'     => 'leave', 'source' => 'manual',
    ]);

    expect($this->service->checkIn($f['user'], geoEvidence(), geoRequest())->result)->toBe(Result::OnLeaveOrHoliday);
});

it('turns a placeholder absent row into a present record on check-in instead of duplicating it', function () {
    $f = geoFixture();
    $placeholder = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'status'     => 'absent', 'source' => 'import',
    ]);

    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect(geoRecords($f['employee']))->toHaveCount(1);
    expect($placeholder->fresh())->status->toBe('present')->source->toBe('gps');
});

// ---------------------------------------------------------------------
// Eligibility and workplace configuration
// ---------------------------------------------------------------------

it('refuses employees who are inactive, terminated or missing', function (array $overrides) {
    $f = geoFixture($overrides);

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::EmployeeNotEligible)->and($result->accepted)->toBeFalse();
    expect(geoRecords($f['employee']))->toHaveCount(0);
})->with([
    'inactive'       => [['is_active' => false]],
    'terminated'     => [['employment_status' => 'terminated']],
    'suspended'      => [['employment_status' => 'suspended']],
    'future_joining' => [['joining_date' => '2026-10-10']],
    'past_leaving'   => [['leaving_date' => '2026-09-01']],
]);

it('gives a user with no employee record nothing', function () {
    $f = geoFixture();
    // The hire-time leave allocations reference the employee (restrict on delete).
    DB::table('time_off_leave_allocations')->where('employee_id', $f['employee']->id)->delete();
    $f['employee']->forceDelete();

    expect($this->service->checkIn($f['user'], geoEvidence(), geoRequest())->result)->toBe(Result::EmployeeNotEligible);
    expect(AttendanceVerification::query()->count())->toBe(0);
});

it('refuses when the workplace is inactive, deleted, not geofenced, or absent', function (Closure $breakIt) {
    $f = geoFixture();
    $breakIt($f);

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::NoLocationConfigured);
    expect(geoRecords($f['employee']))->toHaveCount(0);
})->with([
    'inactive'          => [fn ($f) => $f['location']->update(['is_active' => false])],
    'soft deleted'      => [fn ($f) => $f['location']->delete()],
    'geofence disabled' => [fn ($f) => $f['location']->update(['geofence_enabled' => false])],
    'no workplace'      => [fn ($f) => $f['employee']->update(['work_location_id' => null])],
]);

it('never lets an office with a switched-off fence exempt an employee from location checks', function () {
    $f = geoFixture();
    $f['location']->update(['geofence_enabled' => false]);

    $result = $this->service->checkIn($f['user'], null, geoRequest());

    expect($result->result)->toBe(Result::NoLocationConfigured);
});

it('checks a fully remote employee in without any location and stores none', function () {
    $f = geoFixture();
    $home = WorkLocation::factory()->create(['company_id' => $f['company']->id, 'location_type' => 'home', 'is_active' => true]);
    $f['employee']->update(['work_location_id' => $home->id]);

    $result = $this->service->checkIn($f['user'], null, geoRequest(), (string) Str::uuid());

    expect($result->result)->toBe(Result::RemoteExempt)->and($result->message)->toContain('remote');
    $record = geoRecords($f['employee'])->sole();
    expect($record->status)->toBe('remote')->and($record->source)->toBe('self_service')->and($record->verification_status)->toBe('remote');
    $verification = AttendanceVerification::query()->sole();
    expect($verification->latitude)->toBeNull()->and($verification->method->value)->toBe('none');
});

it('lets an approved remote day (a Home assignment) override an office primary for that day only', function () {
    $f = geoFixture();
    $home = WorkLocation::factory()->create(['company_id' => $f['company']->id, 'location_type' => 'home', 'is_active' => true]);
    EmployeeWorkLocationAssignment::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'work_location_id' => $home->id,
        'valid_from' => '2026-10-05', 'valid_until' => '2026-10-05', 'reason' => 'Approved WFH',
    ]);

    expect($this->service->checkIn($f['user'], null, geoRequest(), (string) Str::uuid())->result)->toBe(Result::RemoteExempt);

    // The next day the assignment no longer applies and the office fence is back.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
    expect($this->service->checkIn($f['user'], geoEvidence(geoOutside()), geoRequest())->result)->toBe(Result::OutsideGeofence);
});

it('supports a temporary second site for hybrid staff, only inside its validity window', function () {
    $f = geoFixture();
    $site = WorkLocation::factory()->geofenced(GEO_LAT + 0.05, GEO_LNG, 200)->create(['company_id' => $f['company']->id, 'name' => 'Client Site']);
    EmployeeWorkLocationAssignment::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'work_location_id' => $site->id,
        'valid_from' => '2026-10-05', 'valid_until' => '2026-10-06', 'reason' => 'Client audit',
    ]);
    $atSite = ['latitude' => GEO_LAT + 0.05, 'longitude' => GEO_LNG];

    $ok = $this->service->checkIn($f['user'], geoEvidence($atSite), geoRequest());
    expect($ok->result)->toBe(Result::Verified);
    expect(AttendanceVerification::query()->sole()->geofence_snapshot['name'])->toBe('Client Site');

    $this->travelTo(CarbonImmutable::parse('2026-10-08 05:00:00', 'UTC'));
    expect($this->service->checkIn($f['user'], geoEvidence($atSite), geoRequest())->result)->toBe(Result::OutsideGeofence);
});

it('flags the same exact coordinates repeated on a different day', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::NeedsReview);
    expect(AttendanceVerification::query()->latest('id')->first()->flags)->toContain('repeated_coordinates');
});

it('rejects a stale location reading', function () {
    $f = geoFixture();

    $result = $this->service->checkIn($f['user'], geoEvidence(['capturedAt' => CarbonImmutable::now('UTC')->subMinutes(10)]), geoRequest());

    expect($result->result)->toBe(Result::StaleLocation);
});

it('refuses every attempt while the feature is switched off', function () {
    $f = geoFixture();
    config(['hr_attendance_geofence.enabled' => false]);

    expect(fn () => $this->service->checkIn($f['user'], geoEvidence(), geoRequest()))->toThrow(RuntimeException::class);
});
