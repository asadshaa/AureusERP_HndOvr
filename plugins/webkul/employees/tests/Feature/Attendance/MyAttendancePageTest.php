<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Support\HrPermissions;

function geoPayload(array $overrides = []): array
{
    return $overrides + [
        'latitude'          => GEO_LAT,
        'longitude'         => GEO_LNG,
        'accuracy'          => 12.5,
        'captured_at'       => CarbonImmutable::now('UTC')->getTimestampMs(),
        'client_request_id' => (string) Str::uuid(),
    ];
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

it('is available to an eligible employee only while the feature is on', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    expect(MyAttendance::canAccess())->toBeTrue();

    config(['hr_attendance_geofence.enabled' => false]);
    expect(MyAttendance::canAccess())->toBeFalse();
});

it('is not available to a user without an eligible employee record', function () {
    $f = geoFixture(['is_active' => false]);
    $this->actingAs($f['user']);

    expect(MyAttendance::canAccess())->toBeFalse();
});

it('renders the check-in screen with the privacy note', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('Not checked in')
        ->assertSee('Check In')
        ->assertSee('only when you tap Check In or Check Out');
});

it('checks the signed-in employee in from a valid browser payload', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->call('checkIn', geoPayload())
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'verified')
        // A profile without a timezone is shown in the attendance timezone (Asia/Karachi), not UTC.
        ->assertSee('Checked in at 10:00 AM');

    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);
});

it('ignores forged employee, company, location, distance and verdict fields in the payload', function () {
    $f = geoFixture();
    [$otherUser, $otherEmployee] = geoEmployee($f['company'], $f['location']);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->call('checkIn', geoPayload(geoOutside() + [
            'employee_id'      => $otherEmployee->id,
            'company_id'       => 999,
            'work_location_id' => 999,
            'distance'         => 0,
            'inside'           => true,
            'verified'         => true,
            'result'           => 'verified',
        ]))
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'outside_geofence');

    expect(AttendanceRecord::query()->count())->toBe(0);
    $verification = AttendanceVerification::query()->sole();
    expect($verification->employee_id)->toBe($f['employee']->id)->and($verification->user_id)->toBe($f['user']->id);
});

it('audits malformed input as invalid coordinates and never stores it', function (array $bad) {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->call('checkIn', geoPayload($bad))
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'invalid_coordinates');

    $verification = AttendanceVerification::query()->sole();
    expect($verification->result)->toBe(Result::InvalidCoordinates)->and($verification->latitude)->toBeNull();
})->with([
    'text latitude'    => [['latitude' => 'abc']],
    'latitude range'   => [['latitude' => 123.0]],
    'huge accuracy'    => [['accuracy' => 99999]],
    'array longitude'  => [['longitude' => ['x']]],
    'bad timestamp'    => [['captured_at' => 'yesterday']],
]);

it('reports a denied permission in plain language and records no attendance', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->call('reportLocationFailure', 'check_in', 'permission_denied', (string) Str::uuid())
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'permission_denied')
        ->assertSee('Location permission is required to check in');

    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('rejects an unknown failure action or code without writing anything', function (string $action, string $code) {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->call('reportLocationFailure', $action, $code, null)
        ->assertSet('outcome.ok', false);

    expect(AttendanceVerification::query()->count())->toBe(0);
})->with([
    'bad action' => ['hr_correction', 'permission_denied'],
    'bad code'   => ['check_in', 'made_up'],
]);

it('checks a remote employee in without any location data', function () {
    $f = geoFixture();
    $home = WorkLocation::factory()->create(['company_id' => $f['company']->id, 'location_type' => 'home', 'is_active' => true]);
    $f['employee']->update(['work_location_id' => $home->id]);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('no location is needed')
        ->call('checkIn', ['client_request_id' => (string) Str::uuid()])
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'remote_exempt');

    expect(AttendanceVerification::query()->sole()->latitude)->toBeNull();
});

it('rate limits repeated attempts and writes nothing once limited', function () {
    $f = geoFixture();
    config(['hr_attendance_geofence.rate_limit_per_minute' => 3]);
    RateLimiter::clear('hr-geo-attendance:'.$f['user']->id);
    $this->actingAs($f['user']);

    $page = Livewire::test(MyAttendance::class);
    foreach (range(1, 3) as $i) {
        $page->call('checkIn', geoPayload(geoOutside()));
    }
    $before = AttendanceVerification::query()->count();

    $page->call('checkIn', geoPayload(geoOutside()))
        ->assertSet('outcome.result', 'rate_limited')
        ->assertSee('Too many attempts');

    expect($before)->toBe(3)->and(AttendanceVerification::query()->count())->toBe(3);
});

it('lets an employee ask for a correction only on their own record', function () {
    $f = geoFixture();
    [$otherUser, $otherEmployee] = geoEmployee($f['company'], $f['location']);
    $others = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $otherEmployee->id, 'attendance_date' => '2026-10-05',
        'check_in'   => '2026-10-05 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->callAction('requestCorrection', ['requested_check_in' => '2026-10-05 04:30:00', 'requested_check_out' => null, 'reason' => 'Wrong time'], ['record' => $others->id]);

    expect(EmployeeRequest::query()->where('employee_id', $otherEmployee->id)->count())->toBe(0);
    expect($others->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 04:00:00');
});

it('submits a correction request on the employee\'s own record for line-manager approval', function () {
    $f = geoFixture();
    $manager = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager[1]->id]);
    $mine = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'check_in'   => '2026-10-05 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->callAction('requestCorrection', ['requested_check_in' => '2026-10-05 03:30:00', 'requested_check_out' => null, 'reason' => 'Badge failed'], ['record' => $mine->id])
        ->assertNotified();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->sole();
    expect($request->status)->toBe('pending_approval')->and($request->payload['attendance_record_id'])->toBe($mine->id);
    expect($mine->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 04:00:00');
});

it('restricts access to HR users when hr_only is enabled', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    config(['hr_attendance_geofence.hr_only' => true]);
    expect(MyAttendance::canAccess())->toBeFalse();

    geoGrant($f['user'], HrPermissions::ViewAttendance);
    expect(MyAttendance::canAccess())->toBeTrue();
});
