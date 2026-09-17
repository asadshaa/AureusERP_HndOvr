<?php

/**
 * TEST ATTENDANCE -- a controlled worked-hours/late/early example, then the
 * 9-item Time Change Request scenario, exercised against the real
 * AttendanceRecord calculation hook and EmployeeRequestService/
 * ApprovalEngine built in Section 4. No new logic; this only proves what
 * already exists behaves per the stated expectations.
 */

use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function taFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(AttendanceWorkflowSeeder::class)->run();

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Employee']);

    return compact('company', 'managerUser', 'manager', 'employeeUser', 'employee');
}

// ---------------------------------------------------------------------
// Controlled example: Scheduled 09:00-17:00, Actual 09:15-16:45.
// ---------------------------------------------------------------------
it('CONTROLLED EXAMPLE: worked hours = 7.5, late = 15, early departure = 15', function () {
    $f = taFixture();
    $date = now()->toDateString();

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 09:15:00",
        'check_out'       => "$date 16:45:00",
        'status'          => 'present',
        'source'          => 'manual',
    ])->fresh();

    expect((float) $record->worked_hours)->toBe(7.5)
        ->and($record->late_minutes)->toBe(15)
        ->and($record->early_departure_minutes)->toBe(15);
});

// ---------------------------------------------------------------------
// 1. Submit a missing check-in correction.
// ---------------------------------------------------------------------
it('1. PASS: submits a correction for a missing check-in', function () {
    $f = taFixture();
    $date = now()->toDateString();

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => null,
        'check_out'       => "$date 17:00:00",
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    expect($record->check_in)->toBeNull();

    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $record,
        $f['employeeUser'],
        ['check_in' => "$date 09:00:00"],
        'System failed to record my check-in this morning.'
    );

    expect($request->status)->toBe('pending_approval')
        ->and($request->payload['original']['check_in'])->toBeNull()
        ->and($request->payload['requested']['check_in'])->toBe("$date 09:00:00");

    // Attendance record itself untouched pending approval.
    expect($record->fresh()->check_in)->toBeNull();
});

// ---------------------------------------------------------------------
// 2. Submit a missing check-out correction.
// ---------------------------------------------------------------------
it('2. PASS: submits a correction for a missing check-out', function () {
    $f = taFixture();
    $date = now()->toDateString();

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 09:00:00",
        'check_out'       => null,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    expect($record->check_out)->toBeNull();

    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $record,
        $f['employeeUser'],
        ['check_out' => "$date 17:00:00"],
        'Forgot to clock out, left at the usual time.'
    );

    expect($request->status)->toBe('pending_approval')
        ->and($request->payload['original']['check_out'])->toBeNull()
        ->and($request->payload['requested']['check_out'])->toBe("$date 17:00:00");

    expect($record->fresh()->check_out)->toBeNull();
});

// ---------------------------------------------------------------------
// 3-4. Approve correction / verify attendance changes.
// ---------------------------------------------------------------------
it('3-4. PASS: approving the missing check-in correction updates the attendance record and recalculates metrics', function () {
    $f = taFixture();
    $date = now()->toDateString();
    $service = app(EmployeeRequestService::class);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => null,
        'check_out'       => "$date 17:00:00",
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $request = $service->requestAttendanceTimeChange($record, $f['employeeUser'], ['check_in' => "$date 09:00:00"], 'Missing check-in.');
    $approved = $service->approve($request, $f['managerUser'], 'Confirmed via door badge log');

    expect($approved->status)->toBe('approved');

    $fresh = $record->fresh();
    expect($fresh->check_in->toDateTimeString())->toBe("$date 09:00:00")
        ->and((float) $fresh->worked_hours)->toBe(8.0)
        ->and($fresh->late_minutes)->toBe(0)
        ->and($fresh->approved_by)->toBe($f['managerUser']->id);
});

// ---------------------------------------------------------------------
// 5-6. Reject correction / verify attendance remains unchanged.
// ---------------------------------------------------------------------
it('5-6. PASS: rejecting the missing check-out correction leaves the attendance record unchanged', function () {
    $f = taFixture();
    $date = now()->toDateString();
    $service = app(EmployeeRequestService::class);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00",
        'scheduled_end'   => "$date 17:00:00",
        'check_in'        => "$date 09:00:00",
        'check_out'       => null,
        'status'          => 'present',
        'source'          => 'manual',
    ]);

    $request = $service->requestAttendanceTimeChange($record, $f['employeeUser'], ['check_out' => "$date 17:00:00"], 'Forgot to clock out.');
    $rejected = $service->reject($request, $f['managerUser'], 'No badge log evidence of leaving at that time.');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('No badge log evidence of leaving at that time.');

    $fresh = $record->fresh();
    expect($fresh->check_out)->toBeNull()
        ->and((float) $fresh->worked_hours)->toBe(0.0)
        ->and($fresh->approved_by)->toBeNull();
});

// ---------------------------------------------------------------------
// 7. Verify audit history.
// ---------------------------------------------------------------------
it('7. PASS: audit history (decisions, actor, reason, timestamps) is preserved for both approved and rejected corrections', function () {
    $f = taFixture();
    $date = now()->toDateString();
    $service = app(EmployeeRequestService::class);

    $approvedRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00", 'scheduled_end' => "$date 17:00:00",
        'check_in'        => null, 'check_out' => "$date 17:00:00", 'status' => 'present', 'source' => 'manual',
    ]);
    $approvedRequest = $service->requestAttendanceTimeChange($approvedRecord, $f['employeeUser'], ['check_in' => "$date 09:00:00"], 'Missing check-in.');
    $approved = $service->approve($approvedRequest, $f['managerUser'], 'Confirmed via badge log');
    $approved->load('approvalRequest.decisions');

    expect($approved->approvalRequest->decisions)->toHaveCount(1);
    $approveDecision = $approved->approvalRequest->decisions->first();
    expect($approveDecision->decision)->toBe('approved')
        ->and($approveDecision->reason)->toBe('Confirmed via badge log')
        ->and($approveDecision->actor_id)->toBe($f['managerUser']->id)
        ->and($approveDecision->decided_at)->not->toBeNull();

    // The original request payload is preserved verbatim regardless of outcome.
    expect($approved->payload['original']['check_in'])->toBeNull()
        ->and($approved->payload['requested']['check_in'])->toBe("$date 09:00:00");

    $rejectedRecord = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id, 'employee_id' => $f['employee']->id,
        'attendance_date' => now()->addDay()->toDateString(),
        'scheduled_start' => now()->addDay()->toDateString().' 09:00:00', 'scheduled_end' => now()->addDay()->toDateString().' 17:00:00',
        'check_in'        => now()->addDay()->toDateString().' 09:00:00', 'check_out' => null, 'status' => 'present', 'source' => 'manual',
    ]);
    $rejectedRequest = $service->requestAttendanceTimeChange($rejectedRecord, $f['employeeUser'], ['check_out' => now()->addDay()->toDateString().' 17:00:00'], 'Forgot to clock out.');
    $rejected = $service->reject($rejectedRequest, $f['managerUser'], 'No evidence.');
    $rejected->load('approvalRequest.decisions');

    expect($rejected->approvalRequest->decisions)->toHaveCount(1);
    $rejectDecision = $rejected->approvalRequest->decisions->first();
    expect($rejectDecision->decision)->toBe('rejected')
        ->and($rejectDecision->reason)->toBe('No evidence.')
        ->and($rejectDecision->actor_id)->toBe($f['managerUser']->id);
});

// ---------------------------------------------------------------------
// 8. Verify unauthorized approval is denied.
// ---------------------------------------------------------------------
it('8. PASS: an unrelated user cannot approve a pending time change request', function () {
    $f = taFixture();
    $date = now()->toDateString();
    $service = app(EmployeeRequestService::class);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00", 'scheduled_end' => "$date 17:00:00",
        'check_in'        => null, 'check_out' => "$date 17:00:00", 'status' => 'present', 'source' => 'manual',
    ]);
    $request = $service->requestAttendanceTimeChange($record, $f['employeeUser'], ['check_in' => "$date 09:00:00"], 'Missing check-in.');

    $stranger = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $stranger->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    expect(app(ApprovalEngine::class)->canAct($request->approvalRequest, $stranger))->toBeFalse();
    expect(fn () => $service->approve($request, $stranger))
        ->toThrow(RuntimeException::class, 'not an approver');

    // The requester approving their own request is denied too.
    expect(fn () => $service->approve($request, $f['employeeUser']))
        ->toThrow(RuntimeException::class, 'not an approver');

    // Record still untouched after both denied attempts.
    expect($record->fresh()->check_in)->toBeNull();
});

// ---------------------------------------------------------------------
// 9. Verify company isolation.
// ---------------------------------------------------------------------
it('9. PASS: a user from another company cannot submit or approve a time change request', function () {
    $f = taFixture();
    $date = now()->toDateString();
    $service = app(EmployeeRequestService::class);

    $otherCompany = Company::factory()->create(['is_active' => true]);
    $outsiderUser = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $outsiderUser->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);

    $record = AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00", 'scheduled_end' => "$date 17:00:00",
        'check_in'        => null, 'check_out' => "$date 17:00:00", 'status' => 'present', 'source' => 'manual',
    ]);

    expect(fn () => $service->requestAttendanceTimeChange($record, $outsiderUser, ['check_in' => "$date 09:00:00"], 'n/a'))
        ->toThrow(RuntimeException::class);

    $request = $service->requestAttendanceTimeChange($record, $f['employeeUser'], ['check_in' => "$date 09:00:00"], 'Missing check-in.');

    expect(fn () => $service->approve($request, $outsiderUser))
        ->toThrow(RuntimeException::class);

    expect($record->fresh()->check_in)->toBeNull();
});
