<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\WorkLocationResource\Pages\ListWorkLocations;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\WorkLocationAssignmentsRelationManager;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\EmployeeWorkLocationAssignment;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

if (! function_exists('geoWorkLocationAdmin')) {
    /** An HR admin for the company with Work Location CRUD plus the given extra permissions. */
    function geoWorkLocationAdmin(Company $company, string ...$extra): User
    {
        return geoHrUser($company, 'view_any_employee_work::location', 'view_employee_work::location', 'create_employee_work::location', 'update_employee_work::location', ...$extra);
    }
}

// ---------------------------------------------------------------------
// Work Locations
// ---------------------------------------------------------------------

it('lists only the signed-in user\'s company work locations', function () {
    $f = geoFixture();
    $foreign = WorkLocation::factory()->geofenced()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign HQ']);
    $this->actingAs(geoWorkLocationAdmin($f['company']));

    Livewire::test(ListWorkLocations::class)
        ->assertCanSeeTableRecords([$f['location']])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('lets a geofence manager create a geofenced workplace from the screen', function () {
    $f = geoFixture();
    $this->actingAs(geoWorkLocationAdmin($f['company'], HrPermissions::ManageAttendanceGeofences));

    Livewire::test(ListWorkLocations::class)
        ->callAction('create', [
            'name'                   => 'Lahore Office',
            'location_type'          => 'office',
            'company_id'             => $f['company']->id,
            'is_active'              => true,
            'geofence_enabled'       => true,
            'latitude'               => '31.5204000',
            'longitude'              => '74.3587000',
            'geofence_radius_meters' => 200,
        ])
        ->assertHasNoActionErrors();

    $created = WorkLocation::query()->where('name', 'Lahore Office')->sole();
    expect($created->geofence_enabled)->toBeTrue()->and($created->geofence_radius_meters)->toBe(200)
        ->and((float) $created->latitude)->toBe(31.5204)->and($created->company_id)->toBe($f['company']->id);
});

it('requires coordinates and a radius once mobile check-in is switched on', function () {
    $f = geoFixture();
    $this->actingAs(geoWorkLocationAdmin($f['company'], HrPermissions::ManageAttendanceGeofences));

    Livewire::test(ListWorkLocations::class)
        ->callAction('create', ['name' => 'Half-configured', 'location_type' => 'office', 'company_id' => $f['company']->id, 'is_active' => true, 'geofence_enabled' => true, 'latitude' => null, 'longitude' => null, 'geofence_radius_meters' => null])
        ->assertHasActionErrors(['latitude' => 'required', 'longitude' => 'required', 'geofence_radius_meters' => 'required']);

    expect(WorkLocation::query()->where('name', 'Half-configured')->exists())->toBeFalse();
});

it('rejects a radius outside the allowed range and out-of-range coordinates', function () {
    $f = geoFixture();
    $this->actingAs(geoWorkLocationAdmin($f['company'], HrPermissions::ManageAttendanceGeofences));

    Livewire::test(ListWorkLocations::class)
        ->callAction('create', ['name' => 'Bad numbers', 'location_type' => 'office', 'company_id' => $f['company']->id, 'is_active' => true, 'geofence_enabled' => true, 'latitude' => 95, 'longitude' => 200, 'geofence_radius_meters' => 9])
        ->assertHasActionErrors(['latitude', 'longitude', 'geofence_radius_meters']);
});

it('silently ignores geofence fields submitted by a user who may not manage geofences', function () {
    $f = geoFixture();
    $this->actingAs(geoWorkLocationAdmin($f['company']));

    Livewire::test(ListWorkLocations::class)
        ->callAction('create', [
            'name'             => 'Sneaky', 'location_type' => 'office', 'company_id' => $f['company']->id, 'is_active' => true,
            'geofence_enabled' => true, 'latitude' => '24.8607000', 'longitude' => '67.0011000', 'geofence_radius_meters' => 5000,
        ]);

    // The workplace itself is created, but none of the geofence data the user had no right to set is stored.
    $created = WorkLocation::query()->where('name', 'Sneaky')->sole();
    expect($created->geofence_enabled)->toBeFalse()
        ->and($created->latitude)->toBeNull()
        ->and($created->longitude)->toBeNull()
        ->and($created->geofence_radius_meters)->toBeNull();
});

it('will not create a workplace in a company the user cannot act in', function () {
    $f = geoFixture();
    $stranger = Company::factory()->create(['is_active' => true]);
    $this->actingAs(geoWorkLocationAdmin($f['company']));

    Livewire::test(ListWorkLocations::class)
        ->callAction('create', ['name' => 'Smuggled', 'location_type' => 'office', 'company_id' => $stranger->id, 'is_active' => true]);

    expect(WorkLocation::query()->where('name', 'Smuggled')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// Attendance list: verification status, evidence, review, corrections
// ---------------------------------------------------------------------

/** A flagged (needs-review) GPS check-in plus the records/users the UI tests need. */
function geoFlaggedCheckIn(): array
{
    $f = geoFixture();
    app(GeofencedAttendanceService::class)->checkIn($f['user'], geoEvidence(['accuracy' => 80.0]), geoRequest());
    $f['record'] = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();

    return $f;
}

it('shows the verification status and filters on it', function () {
    $f = geoFlaggedCheckIn();
    $manual = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-01',
        'check_in'   => '2026-10-01 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->assertCanSeeTableRecords([$f['record'], $manual])
        ->assertSee('Needs checking')
        ->filterTable('verification_status', 'needs_review')
        ->assertCanSeeTableRecords([$f['record']])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('offers review actions only to a permitted reviewer, and applying one updates the record', function () {
    $f = geoFlaggedCheckIn();
    $viewer = geoHrUser($f['company'], HrPermissions::ViewAttendance);
    $this->actingAs($viewer);
    Livewire::test(ManageAttendanceRecords::class)
        ->assertTableActionHidden('approve_verification', $f['record'])
        ->assertTableActionHidden('reject_verification', $f['record']);

    $reviewer = geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ReviewAttendanceVerifications);
    $this->actingAs($reviewer);
    Livewire::test(ManageAttendanceRecords::class)
        ->assertTableActionVisible('approve_verification', $f['record'])
        ->callTableAction('approve_verification', $f['record'], ['note' => 'Confirmed on CCTV']);

    expect($f['record']->fresh()->verification_status)->toBe('reviewed');
});

it('does not offer the employee the chance to review or edit their own GPS attendance', function () {
    $f = geoFlaggedCheckIn();
    $owner = geoGrant($f['user'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance, HrPermissions::ReviewAttendanceVerifications, HrPermissions::ViewAllRecords);
    $this->actingAs($owner);

    Livewire::test(ManageAttendanceRecords::class)
        ->assertTableActionHidden('approve_verification', $f['record'])
        ->assertTableActionHidden('reject_verification', $f['record'])
        ->assertTableActionHidden('edit', $f['record'])
        ->assertTableActionHidden('delete', $f['record']);
});

it('routes an HR edit of a GPS record through an audited, reasoned correction', function () {
    $f = geoFlaggedCheckIn();
    $hr = geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance);
    $this->actingAs($hr);

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('edit', $f['record'], [
            'employee_id'       => $f['employee']->id,
            'attendance_date'   => '2026-10-05',
            'check_in'          => '2026-10-05 04:40:00',
            'status'            => 'present',
            'correction_reason' => 'Door log shows arrival at 04:40',
        ])
        ->assertHasNoTableActionErrors();

    expect($f['record']->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 04:40:00');
    $audit = AttendanceVerification::query()->where('action', 'hr_correction')->sole();
    expect($audit->metadata['reason'])->toBe('Door log shows arrival at 04:40')->and($audit->user_id)->toBe($hr->id);
});

it('will not save an HR edit of a GPS record without a reason', function () {
    $f = geoFlaggedCheckIn();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('edit', $f['record'], [
            'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05', 'check_in' => '2026-10-05 04:40:00', 'status' => 'present', 'correction_reason' => '',
        ])
        ->assertHasTableActionErrors(['correction_reason']);

    expect($f['record']->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 05:00:00');
});

it('does not let anyone create a GPS-sourced record by hand', function () {
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('create', data: ['employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04', 'check_in' => '2026-10-04 04:00:00', 'status' => 'present', 'source' => 'gps'])
        ->assertHasTableActionErrors(['source']);

    expect(AttendanceRecord::query()->where('attendance_date', '2026-10-04')->exists())->toBeFalse();
});

it('still lets HR create an ordinary manual record and edit it without a reason', function () {
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('create', data: ['employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04', 'check_in' => '2026-10-04 04:00:00', 'status' => 'present', 'source' => 'manual'])
        ->assertHasNoTableActionErrors();

    $record = AttendanceRecord::query()->where('attendance_date', '2026-10-04')->sole();
    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('edit', $record, ['employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04', 'check_in' => '2026-10-04 04:20:00', 'status' => 'present', 'source' => 'manual'])
        ->assertHasNoTableActionErrors();

    expect($record->fresh()->check_in->toDateTimeString())->toBe('2026-10-04 04:20:00');
    expect(AttendanceVerification::query()->count())->toBe(0);
});

it('shows raw coordinates and IP in the evidence view only to those holding the evidence permission', function () {
    $f = geoFlaggedCheckIn();
    $verifications = fn () => $f['record']->verifications()->with(['workLocation', 'reviewer', 'user'])->get();
    $render = fn () => view('employees::filament.components.attendance-verification-evidence', ['verifications' => $verifications(), 'timezone' => 'UTC'])->render();

    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance));
    $plain = $render();
    expect($plain)->toContain('Distance from workplace')->toContain('GPS accuracy')
        ->not->toContain('24.8607000')->not->toContain('10.0.0.7')->not->toContain('Pest/Geo');

    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ViewAttendanceLocationEvidence));
    $full = $render();
    expect($full)->toContain('24.8607000')->toContain('10.0.0.7')->toContain('Pest/Geo');
});

// ---------------------------------------------------------------------
// Employee -> additional workplaces
// ---------------------------------------------------------------------

it('lets HR add and remove an approved-remote-day assignment on an employee', function () {
    $f = geoFixture();
    $home = WorkLocation::factory()->create(['company_id' => $f['company']->id, 'location_type' => 'home', 'is_active' => true, 'name' => 'Home']);
    $this->actingAs(geoHrUser($f['company'], 'view_employee_employee', 'update_employee_employee', 'view_any_employee_employee'));

    $manager = Livewire::test(WorkLocationAssignmentsRelationManager::class, ['ownerRecord' => $f['employee'], 'pageClass' => ViewEmployee::class])
        ->callTableAction('create', data: ['work_location_id' => $home->id, 'valid_from' => '2026-10-05', 'valid_until' => '2026-10-05', 'reason' => 'Approved WFH'])
        ->assertHasNoTableActionErrors();

    $assignment = EmployeeWorkLocationAssignment::query()->where('employee_id', $f['employee']->id)->sole();
    expect($assignment->work_location_id)->toBe($home->id)->and($assignment->company_id)->toBe($f['company']->id);

    $manager->callTableAction('delete', $assignment);
    expect(EmployeeWorkLocationAssignment::query()->count())->toBe(0);
});

it('only offers a company\'s own active workplaces when assigning', function () {
    $f = geoFixture();
    $foreign = WorkLocation::factory()->geofenced()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign Site']);
    $this->actingAs(geoHrUser($f['company'], 'view_employee_employee', 'update_employee_employee', 'view_any_employee_employee'));

    Livewire::test(WorkLocationAssignmentsRelationManager::class, ['ownerRecord' => $f['employee'], 'pageClass' => ViewEmployee::class])
        ->callTableAction('create', data: ['work_location_id' => $foreign->id, 'reason' => 'attack']);

    expect(EmployeeWorkLocationAssignment::query()->count())->toBe(0);
});

it('makes the assignment list read-only for someone who cannot manage the employee', function () {
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], 'view_employee_employee', 'view_any_employee_employee'));

    Livewire::test(WorkLocationAssignmentsRelationManager::class, ['ownerRecord' => $f['employee'], 'pageClass' => ViewEmployee::class])
        ->assertTableActionHidden('create');
});

it('renders the "Use my current location" helper as a working Alpine component, not as visible script text', function () {
    $html = view('employees::filament.components.use-my-location')->render();

    // A double quote inside x-data="..." ends the attribute early and dumps the
    // rest of the script onto the page as text (seen live on the Work Location form).
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $root = $dom->getElementsByTagName('div')->item(0);

    expect($root->getAttribute('x-data'))->toContain('getCurrentPosition')->toContain('enableHighAccuracy')
        ->and(trim(preg_replace('/\s+/', ' ', $root->textContent)))->toBe('Use my current location');
});
