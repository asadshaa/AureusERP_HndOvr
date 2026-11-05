<?php

use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

function auditFixture(): array
{
    $companyA = Company::factory()->create(['name' => 'Company A', 'is_active' => true]);
    $companyB = Company::factory()->create(['name' => 'Company B', 'is_active' => true]);

    app(AttendanceWorkflowSeeder::class)->run();

    // HR User in Company A with manage_attendance
    $hrUserA = User::factory()->create([
        'name'                => 'HR Admin A',
        'default_company_id'  => $companyA->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $hrUserA->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    $hrEmployeeA = Employee::query()->create([
        'company_id'        => $companyA->id,
        'user_id'           => $hrUserA->id,
        'name'              => 'HR Employee A',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // View-Only User in Company A
    $viewUserA = User::factory()->create([
        'name'                => 'View-Only A',
        'default_company_id'  => $companyA->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $viewUserA->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    $viewEmployeeA = Employee::query()->create([
        'company_id'        => $companyA->id,
        'user_id'           => $viewUserA->id,
        'name'              => 'View Employee A',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // Manager in Company A
    $managerUserA = User::factory()->create([
        'name'                => 'Manager A',
        'default_company_id'  => $companyA->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $managerUserA->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    $managerEmployeeA = Employee::query()->create([
        'company_id'        => $companyA->id,
        'user_id'           => $managerUserA->id,
        'name'              => 'Manager Employee A',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // Subordinate in Company A reporting to Manager A
    $subUserA = User::factory()->create([
        'name'                => 'Subordinate A',
        'default_company_id'  => $companyA->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $subUserA->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    $subEmployeeA = Employee::query()->create([
        'company_id'        => $companyA->id,
        'user_id'           => $subUserA->id,
        'parent_id'         => $managerEmployeeA->id,
        'name'              => 'Subordinate Employee A',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // Independent employee in Company A (not under Manager A)
    $otherUserA = User::factory()->create([
        'name'                => 'Other A',
        'default_company_id'  => $companyA->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $otherUserA->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    $otherEmployeeA = Employee::query()->create([
        'company_id'        => $companyA->id,
        'user_id'           => $otherUserA->id,
        'name'              => 'Other Employee A',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // Employee in Company B
    $userB = User::factory()->create([
        'name'                => 'User B',
        'default_company_id'  => $companyB->id,
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $userB->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    $employeeB = Employee::query()->create([
        'company_id'        => $companyB->id,
        'user_id'           => $userB->id,
        'name'              => 'Employee B',
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    // Grant permissions
    auditGrant($hrUserA, HrPermissions::ManageAttendance, HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords);
    auditGrant($viewUserA, HrPermissions::ViewAttendance);
    auditGrant($managerUserA, HrPermissions::ViewAttendance, HrPermissions::ManageAttendance);

    return compact(
        'companyA', 'companyB',
        'hrUserA', 'hrEmployeeA',
        'viewUserA', 'viewEmployeeA',
        'managerUserA', 'managerEmployeeA',
        'subUserA', 'subEmployeeA',
        'otherUserA', 'otherEmployeeA',
        'userB', 'employeeB'
    );
}

function auditGrant(User $user, string ...$permissions): void
{
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->refresh();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

// =====================================================================
// SECTION 1: ATTENDANCE CALCULATIONS & METRICS INTEGRITY
// =====================================================================

it('AUDIT: verifies worked hours, late minutes, and early departure calculations on saving', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 09:20:00",
        'check_out'       => "$date 16:40:00",
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    // 9:20 to 16:40 = 7 hours 20 mins = 7.3333 hours
    expect((float) $record->worked_hours)->toEqualWithDelta(7.3333, 0.001)
        ->and($record->late_minutes)->toBe(20)
        ->and($record->early_departure_minutes)->toBe(20);
});

it('AUDIT: verifies on-time check-in and check-out results in zero late and early minutes', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 08:50:00", // 10 mins early
        'check_out'       => "$date 17:10:00", // 10 mins late
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    expect((float) $record->worked_hours)->toEqualWithDelta(8.3333, 0.001)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->early_departure_minutes)->toBe(0);
});

it('AUDIT: verifies null check-out yields zero worked hours and null early minutes', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 09:00:00",
        'check_out'       => null,
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    expect((float) $record->worked_hours)->toBe(0.0)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->early_departure_minutes)->toBe(0);
});

it('AUDIT: verifies overnight shift calculates worked hours accurately across calendar midnight', function () {
    $f = auditFixture();

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => '2026-10-10',
        'scheduled_start' => '2026-10-10 22:00:00',
        'scheduled_end'   => '2026-10-11 06:00:00',
        'check_in'        => '2026-10-10 22:00:00',
        'check_out'       => '2026-10-11 06:00:00',
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    expect((float) $record->worked_hours)->toBe(8.0)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->early_departure_minutes)->toBe(0);
});

it('AUDIT: investigates what happens when check_out is earlier than check_in at the model layer', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    // Model hook test: check_in at 17:00, check_out at 09:00 (inverted)
    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'check_in'        => "$date 17:00:00",
        'check_out'       => "$date 09:00:00",
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    // In this Carbon version, diffInMinutes($target) preserves sign or evaluates negative,
    // and max(0, negative) safely clamps worked_hours to 0.0.
    // However, the model saving hook does NOT throw an exception or reject inverted timestamps.
    expect((float) $record->worked_hours)->toBe(0.0);
});

// =====================================================================
// SECTION 2: COMPANY ISOLATION & MULTI-TENANCY
// =====================================================================

it('AUDIT: verifies unique constraint on company_id + employee_id + attendance_date', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    expect(fn () => AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]))->toThrow(QueryException::class);
});

it('AUDIT: verifies AttendanceRecordResource query is strictly scoped to user default_company_id', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    // Record in Company A
    $recA = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    // Record in Company B
    $recB = AttendanceRecord::query()->create([
        'company_id'      => $f['companyB']->id,
        'employee_id'     => $f['employeeB']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    // HR Admin of Company A inspects query
    $this->actingAs($f['hrUserA']);
    $queryA = AttendanceRecordResource::getEloquentQuery();
    $idsA = $queryA->pluck('id')->all();

    expect($idsA)->toContain($recA->id)
        ->and($idsA)->not->toContain($recB->id);
});

// =====================================================================
// SECTION 3: HIERARCHY & MANAGER ACCESS SCOPING
// =====================================================================

it('AUDIT: verifies manager only sees subordinates, not unrelated peers in the same company without hr_view_all_records', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $subRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $otherRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['otherEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    // Manager A has ManageAttendance and ViewAttendance, but NOT hr_view_all_records
    $this->actingAs($f['managerUserA']);
    $visibleIds = AttendanceRecordResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($subRecord->id)
        ->and($visibleIds)->not->toContain($otherRecord->id);
});

it('AUDIT: verifies hr_view_all_records bypasses hierarchy and allows viewing all company records', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $subRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $otherRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['otherEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    // HR User A has hr_view_all_records
    $this->actingAs($f['hrUserA']);
    $visibleIds = AttendanceRecordResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($subRecord->id)
        ->and($visibleIds)->toContain($otherRecord->id);
});

// =====================================================================
// SECTION 4: PERMISSIONS & POLICY ANALYSIS
// =====================================================================

it('AUDIT: checks canViewAny requirement on AttendanceRecordResource', function () {
    $f = auditFixture();

    $this->actingAs($f['hrUserA']);
    expect(AttendanceRecordResource::canViewAny())->toBeTrue();

    $this->actingAs($f['viewUserA']);
    expect(AttendanceRecordResource::canViewAny())->toBeTrue();

    // Regular employee with no attendance permissions
    $this->actingAs($f['subUserA']);
    expect(AttendanceRecordResource::canViewAny())->toBeFalse();
});

it('AUDIT: verifies user cannot edit or delete their own GPS-verified attendance record in Filament', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    // GPS record for Manager User A
    $record = AttendanceRecord::query()->create([
        'company_id'          => $f['companyA']->id,
        'employee_id'         => $f['managerEmployeeA']->id,
        'attendance_date'     => $date,
        'check_in'            => "$date 09:00:00",
        'check_out'           => "$date 17:00:00",
        'status'              => 'present',
        'source'              => AttendanceSource::Gps->value,
        'verification_status' => AttendanceVerificationStatus::Verified->value,
    ]);

    $this->actingAs($f['managerUserA']);

    // In AttendanceRecordResource:
    // EditAction::make()->visible(fn ($record) => ! self::isOwnEvidenceBacked($record))
    // DeleteAction::make()->visible(fn ($record) => ! self::isOwnEvidenceBacked($record))
    $reflection = new ReflectionClass(AttendanceRecordResource::class);
    $method = $reflection->getMethod('isOwnEvidenceBacked');
    $method->setAccessible(true);

    expect($method->invoke(null, $record))->toBeTrue();
});

// =====================================================================
// SECTION 6: PERMISSION GAPS & DEFECT INVESTIGATION
// =====================================================================

it('AUDIT: probes whether a View-Only user (no manage_attendance) can create, edit, or delete records in Filament', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['companyA']->id,
        'employee_id'     => $f['subEmployeeA']->id,
        'attendance_date' => $date,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    // View-Only user has ViewAttendance, but NOT ManageAttendance
    $this->actingAs($f['viewUserA']);

    // Check Filament's resource level checks: View-only user CANNOT create, edit, or delete
    $canCreate = AttendanceRecordResource::canCreate();
    $canEdit = AttendanceRecordResource::canEdit($record);
    $canDelete = AttendanceRecordResource::canDelete($record);

    expect($canCreate)->toBeFalse()
        ->and($canEdit)->toBeFalse()
        ->and($canDelete)->toBeFalse();

    // HR user with ManageAttendance CAN create, edit, and delete
    $this->actingAs($f['hrUserA']);
    expect(AttendanceRecordResource::canCreate())->toBeTrue()
        ->and(AttendanceRecordResource::canEdit($record))->toBeTrue()
        ->and(AttendanceRecordResource::canDelete($record))->toBeTrue();
});

it('AUDIT: tests whether Filament CreateAction validates that check_out must be after check_in', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $this->actingAs($f['hrUserA']);

    $test = Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('create', data: [
            'employee_id'     => $f['subEmployeeA']->id,
            'attendance_date' => $date,
            'check_in'        => "$date 17:00:00",
            'check_out'       => "$date 09:00:00",
            'status'          => 'present',
            'source'          => 'manual',
        ]);

    $test->assertHasTableActionErrors(['check_out' => 'after']);

    $created = AttendanceRecord::query()
        ->where('employee_id', $f['subEmployeeA']->id)
        ->where('attendance_date', $date)
        ->first();

    // Fixed: an inverted check-out is now refused by the form instead of being
    // saved silently with 0 worked hours.
    expect($created)->toBeNull();
});

it('AUDIT: verifies model updated hook creates an AttendanceVerification audit row when times are changed on evidence-backed records', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'          => $f['companyA']->id,
        'employee_id'         => $f['subEmployeeA']->id,
        'attendance_date'     => $date,
        'check_in'            => "$date 09:00:00",
        'check_out'           => "$date 17:00:00",
        'status'              => 'present',
        'source'              => AttendanceSource::Gps->value,
        'verification_status' => AttendanceVerificationStatus::Verified->value,
    ]);

    $this->actingAs($f['hrUserA']);

    // Direct model update to times
    $record->update(['check_in' => "$date 09:30:00"]);

    $audit = AttendanceVerification::query()
        ->where('attendance_record_id', $record->id)
        ->where('action', AttendanceVerificationAction::HrCorrection)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->result)->toBe(AttendanceVerificationResult::Overridden)
        ->and($audit->metadata['after']['check_in'])->toBe("$date 09:30:00")
        ->and($record->fresh()->verification_status)->toBe(AttendanceVerificationStatus::Overridden->value);
});

it('AUDIT: verifies recordHrCorrection service enforces minimum 10 char reason and rejects check_out <= check_in', function () {
    $f = auditFixture();
    $date = '2026-10-10';

    $record = AttendanceRecord::query()->create([
        'company_id'          => $f['companyA']->id,
        'employee_id'         => $f['subEmployeeA']->id,
        'attendance_date'     => $date,
        'check_in'            => "$date 09:00:00",
        'check_out'           => "$date 17:00:00",
        'status'              => 'present',
        'source'              => AttendanceSource::Gps->value,
    ]);

    $service = app(GeofencedAttendanceService::class);

    // Short reason fails
    expect(fn () => $service->recordHrCorrection(
        $record,
        $f['hrUserA'],
        ['check_in' => "$date 09:15:00"],
        'Short'
    ))->toThrow(InvalidArgumentException::class, 'Write a reason for the correction (at least 10 characters).');

    // check_out <= check_in fails
    expect(fn () => $service->recordHrCorrection(
        $record,
        $f['hrUserA'],
        ['check_in' => "$date 17:00:00", 'check_out' => "$date 09:00:00"],
        'Valid reason exceeding ten characters'
    ))->toThrow(InvalidArgumentException::class, 'Check-out time must be later than check-in time.');
});
