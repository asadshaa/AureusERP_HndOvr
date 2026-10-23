<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\AttendanceRecordsRelationManager;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Support\HrPermissions;

beforeEach(function () {
    $this->service = app(GeofencedAttendanceService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
});

it('blocks an immediate check-out within 1 hour of check-in', function () {
    $f = geoFixture();
    $in = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    expect($in->accepted)->toBeTrue();

    // 30 minutes later - still inside the 1-hour lockout
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30:00', 'UTC'));

    $state = $this->service->todayState($f['user']);
    expect($state['lockout_remaining_minutes'])->toBe(30);

    $out = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());
    expect($out->accepted)->toBeFalse()
        ->and($out->result)->toBe(Result::TooEarlyForCheckOut);

    // Record must still be open
    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    expect($record->check_out)->toBeNull();
});

it('allows check-out once 1 hour has elapsed', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // 61 minutes later - past 1-hour lockout
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:01:00', 'UTC'));

    $state = $this->service->todayState($f['user']);
    expect($state['lockout_remaining_minutes'])->toBe(0);

    $out = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());
    expect($out->accepted)->toBeTrue();

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    expect($record->check_out)->not->toBeNull();
});

it('sets status to half_day when worked hours are between 4.0 and 7.5 hours', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // 5 hours later
    $this->travelTo(CarbonImmutable::parse('2026-10-05 14:00:00', 'UTC'));

    $out = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());
    expect($out->accepted)->toBeTrue();

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    expect($record->status)->toBe('half_day')
        ->and((float) $record->worked_hours)->toBeGreaterThanOrEqual(4.9);
});

it('sets status to present when worked hours reach or exceed 7.5 hours', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // 8 hours later
    $this->travelTo(CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'));

    $out = $this->service->checkOut($f['user'], geoEvidence(), geoRequest());
    expect($out->accepted)->toBeTrue();

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    expect($record->status)->toBe('present')
        ->and((float) $record->worked_hours)->toBeGreaterThanOrEqual(7.9);
});

it('never closes an open shift on its own unless auto-close is explicitly enabled', function () {
    config(['hr_attendance_geofence.auto_close_policy' => 'none']);
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $now = CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC');
    $this->travelTo($now);

    expect($this->service->autoCompleteCompletedShifts($now))->toBe(0);
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first()->check_out)->toBeNull();
});

it('with auto_close_policy=scheduled_end, closes a shift at its scheduled end and sends it for review', function () {
    config(['hr_attendance_geofence.auto_close_policy' => 'scheduled_end']);
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    $record->newQuery()->whereKey($record->id)->update(['scheduled_end' => '2026-10-05 13:00:00']);

    $now = CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC');
    $this->travelTo($now);

    expect($this->service->autoCompleteCompletedShifts($now))->toBe(1);

    $record = $record->fresh();
    expect($record->check_out->toDateTimeString())->toBe('2026-10-05 13:00:00')
        ->and($record->status)->toBe('present')
        ->and($record->verification_status)->toBe('needs_review');

    $verification = AttendanceVerification::query()
        ->where('employee_id', $f['employee']->id)
        ->where('action', 'check_out')
        ->first();
    expect($verification)->not->toBeNull()
        ->and($verification->method->value)->toBe('manual')
        ->and($verification->review_status)->toBe('pending')
        ->and($verification->flags)->toContain('auto_closed', 'auto_completed_full_shift');
});

it('with auto_close_policy=scheduled_end, leaves a shift without a schedule open (no invented end time)', function () {
    config(['hr_attendance_geofence.auto_close_policy' => 'scheduled_end']);
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $now = CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC');
    $this->travelTo($now);

    expect($this->service->autoCompleteCompletedShifts($now))->toBe(0);
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first()->check_out)->toBeNull();
});

it('executes hr:auto-complete-shifts artisan command successfully', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->first();
    $record->newQuery()->whereKey($record->id)->update(['scheduled_end' => '2026-10-05 13:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:30:00', 'UTC'));

    config(['hr_attendance_geofence.auto_close_policy' => 'none']);
    expect(Artisan::call('hr:auto-complete-shifts', ['--force' => true]))->toBe(0);
    expect($record->fresh()->check_out)->toBeNull();

    config(['hr_attendance_geofence.auto_close_policy' => 'scheduled_end']);
    expect(Artisan::call('hr:auto-complete-shifts'))->toBe(0);
    expect($record->fresh()->check_out)->not->toBeNull();
});

it('exposes employee attendance records relation manager and employee filter for HR', function () {
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance);
    $this->actingAs($hrUser);

    // Verify AttendanceRecordsRelationManager is registered on EmployeeResource
    $relations = EmployeeResource::getRelations();
    $relationClasses = [];
    foreach ($relations as $rel) {
        if ($rel instanceof RelationGroup) {
            foreach ($rel->getManagers() as $m) {
                $relationClasses[] = is_string($m) ? $m : get_class($m);
            }
        } elseif (is_string($rel)) {
            $relationClasses[] = $rel;
        }
    }

    expect($relationClasses)->toContain(AttendanceRecordsRelationManager::class);

    // Verify filter exists on AttendanceRecordResource
    $table = AttendanceRecordResource::table(new Table(new ManageAttendanceRecords));
    $filters = $table->getFilters();
    expect(array_keys($filters))->toContain('employee_id');
});
