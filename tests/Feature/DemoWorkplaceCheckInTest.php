<?php

/**
 * The demo companies' geofenced workplace (DemoActivitySeeder::seedWorkplace(),
 * demo:seed --workplaces): one active office with the config radius, given
 * to every active employee without a workplace, tracked so demo:purge undoes
 * it, unchanged on a re-run. An employee then checks in from inside the
 * fence and is refused outside it. A fresh company stands in for ShipLine,
 * with ShipLine's catalogued workplace.
 */

require_once __DIR__.'/../../plugins/webkul/employees/tests/Feature/Attendance/GeoTestHelpers.php';

use App\Services\Demo\DemoPurger;
use App\Services\Demo\DemoRecorder;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\DemoActivitySeeder;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Models\Company;

beforeEach(function () {
    // Monday 5 Oct 2026, 10:00 in Karachi.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
    config(['hr_attendance_geofence.enabled' => true]);
    app(AttendanceWorkflowSeeder::class)->run();

    $this->company = Company::factory()->create(['name' => 'Demo Workplace Test Co', 'is_active' => true]);
    $this->spec = DemoCatalog::COMPANIES['ShipLine']['workplace'];
    $this->recorder = new DemoRecorder;
    $this->seeder = app()->make(DemoActivitySeeder::class, ['recorder' => $this->recorder]);

    [$this->sanaUser, $this->sana] = geoEmployee($this->company, null, ['name' => 'Sana Workplace Test']);
    [$this->bilalUser, $this->bilal] = geoEmployee($this->company, null, ['name' => 'Bilal Workplace Test']);
    $this->warehouse = WorkLocation::factory()->geofenced(24.9, 67.1, 300)->create(['company_id' => $this->company->id, 'name' => 'Warehouse', 'is_active' => true]);
    [, $this->warehouseStaff] = geoEmployee($this->company, $this->warehouse, ['name' => 'Warehouse Workplace Test']);
    [, $this->former] = geoEmployee($this->company, null, ['name' => 'Former Workplace Test', 'is_active' => false]);

    // demo:seed acts as the company's HR manager, who may manage geofences.
    $this->hr = geoHrUser($this->company, HrPermissions::ManageAttendanceGeofences);
    $this->actingAs($this->hr);
});

/** @return array{workplace: ?WorkLocation, created: bool, assigned: int} */
function seedDemoWorkplace(): array
{
    $result = test()->seeder->seedWorkplace(test()->company, test()->spec);
    test()->recorder->stop();

    return $result;
}

it('creates one active geofenced workplace, gives it to the unassigned staff and changes nothing on a re-run', function () {
    $first = seedDemoWorkplace();
    $workplace = $first['workplace'];

    expect($first['created'])->toBeTrue()
        ->and($first['assigned'])->toBe(3)
        ->and($workplace->name)->toBe('ShipLine Head Office, Keamari, Karachi (demo)')
        ->and($workplace->location_number)->toContain('HR must confirm the coordinates and radius')
        ->and($workplace->is_active)->toBeTrue()
        ->and($workplace->geofence_enabled)->toBeTrue()
        ->and($workplace->geofence_radius_meters)->toBe(150)
        ->and((float) $workplace->latitude)->toBe(24.8372)
        ->and((float) $workplace->longitude)->toBe(66.9784)
        ->and($workplace->isGeofenceUsable())->toBeTrue()
        ->and($workplace->creator_id)->toBe($this->hr->id);

    // The active staff without a workplace get it (the HR user too); an existing choice and a former employee are left alone.
    expect($this->sana->fresh()->work_location_id)->toBe($workplace->id)
        ->and($this->bilal->fresh()->work_location_id)->toBe($workplace->id)
        ->and($this->warehouseStaff->fresh()->work_location_id)->toBe($this->warehouse->id)
        ->and($this->former->fresh()->work_location_id)->toBeNull();

    // HR moves the fence: a re-run neither creates another workplace nor undoes the change.
    $workplace->update(['geofence_radius_meters' => 250]);
    $again = $this->seeder->seedWorkplace($this->company, $this->spec);

    expect($again['created'])->toBeFalse()
        ->and($again['assigned'])->toBe(0)
        ->and($again['workplace']->id)->toBe($workplace->id)
        ->and($workplace->fresh()->geofence_radius_meters)->toBe(250)
        ->and(WorkLocation::query()->where('company_id', $this->company->id)->count())->toBe(2);
});

it('lets an employee check in inside the fence and refuses a check-in from outside it', function () {
    $service = app(GeofencedAttendanceService::class);

    // Before: no workplace, so a check-in is refused (what ShipLine and OpenPort staff saw).
    $before = $service->checkIn($this->sanaUser, geoEvidence(['latitude' => 24.8372, 'longitude' => 66.9784]), geoRequest());
    expect($before->accepted)->toBeFalse()->and($before->result)->toBe(Result::NoLocationConfigured);

    $workplace = seedDemoWorkplace()['workplace'];
    expect($service->resolveMode($this->sana->fresh(), '2026-10-05')['mode'])->toBe('gps');

    // About 1.1 km north of the office.
    $outside = $service->checkIn($this->bilalUser, geoEvidence(['latitude' => 24.8472, 'longitude' => 66.9784]), geoRequest());
    expect($outside->accepted)->toBeFalse()
        ->and($outside->result)->toBe(Result::OutsideGeofence)
        ->and(AttendanceRecord::query()->where('employee_id', $this->bilal->id)->exists())->toBeFalse();

    // About 15 m from the office.
    $inside = $service->checkIn($this->sanaUser, geoEvidence(['latitude' => 24.8373, 'longitude' => 66.9785]), geoRequest());
    expect($inside->accepted)->toBeTrue()->and($inside->result)->toBe(Result::Verified);

    $record = AttendanceRecord::query()->where('employee_id', $this->sana->id)->sole();
    expect($record->source)->toBe('gps')
        ->and($record->company_id)->toBe($this->company->id)
        ->and($record->attendance_date->toDateString())->toBe('2026-10-05')
        ->and($record->checkInVerification->work_location_id)->toBe($workplace->id);
});

it('is removed by demo:purge, which puts the employees back as they were', function () {
    $workplace = seedDemoWorkplace()['workplace'];

    $result = app(DemoPurger::class)->purge([$this->company->id]);

    expect($result['failed'])->toBe(0)
        ->and(WorkLocation::withTrashed()->find($workplace->id))->toBeNull()
        ->and($this->sana->fresh()->work_location_id)->toBeNull()
        ->and($this->bilal->fresh()->work_location_id)->toBeNull()
        ->and($this->warehouseStaff->fresh()->work_location_id)->toBe($this->warehouse->id)
        ->and($this->warehouse->fresh())->not->toBeNull();
});
