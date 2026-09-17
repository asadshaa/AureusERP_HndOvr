<?php

/**
 * Section 4 ("IMPLEMENTATION SECTION 4 -- ATTENDANCE") TIME CHANGE REQUEST:
 * Employee -> Time Change Request -> Line Manager Approval -> Approved/
 * Rejected. Exercises EmployeeRequestService::requestAttendanceTimeChange()
 * end to end against the real ApprovalEngine (routed via the same
 * hierarchy_route mechanism every other HR workflow in this app uses),
 * reusing EmployeeRequest/EmployeeRequestType -- no parallel request or
 * approval system.
 */

use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function attendanceFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(AttendanceWorkflowSeeder::class)->run();

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Employee']);

    $record = AttendanceRecord::query()->create([
        'company_id'       => $company->id,
        'employee_id'      => $employee->id,
        'attendance_date'  => now()->toDateString(),
        'scheduled_start'  => now()->setTime(9, 0),
        'scheduled_end'    => now()->setTime(17, 0),
        'check_in'         => now()->setTime(9, 15),
        'check_out'        => now()->setTime(17, 0),
        'status'           => 'present',
        'source'           => 'manual',
    ]);

    return compact('company', 'managerUser', 'manager', 'employeeUser', 'employee', 'record');
}

it('provisions the attendance_time_change request type and a line-manager workflow per company, idempotently', function () {
    $company = Company::factory()->create(['is_active' => true]);

    app(AttendanceWorkflowSeeder::class)->run();
    app(AttendanceWorkflowSeeder::class)->run();

    $type = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'attendance_time_change')->get();
    expect($type)->toHaveCount(1)
        ->and($type->first()->category)->toBe('attendance_correction')
        ->and($type->first()->is_financial)->toBeFalse();

    $workflow = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'attendance_time_change')->get();
    expect($workflow)->toHaveCount(1);
    expect($workflow->first()->steps)->toHaveCount(1)
        ->and($workflow->first()->steps->first()->hierarchy_route)->toBe('requester_manager');
});

it('retains all the required attendance fields and derives worked/late/early metrics automatically', function () {
    $f = attendanceFixture();
    $record = $f['record']->fresh();

    expect($record->employee_id)->toBe($f['employee']->id)
        ->and($record->company_id)->toBe($f['company']->id)
        ->and($record->attendance_date)->not->toBeNull()
        ->and($record->check_in)->not->toBeNull()
        ->and($record->check_out)->not->toBeNull()
        ->and((float) $record->worked_hours)->toBeGreaterThan(0)
        ->and($record->late_minutes)->toBe(15)
        ->and($record->early_departure_minutes)->toBe(0)
        ->and($record->status)->toBe('present')
        ->and($record->source)->toBe('manual')
        ->and($record->notes)->toBeNull();
});

it('submits a time change request that routes to the line manager and preserves the original request', function () {
    $f = attendanceFixture();

    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $f['record'],
        $f['employeeUser'],
        ['check_in' => $f['record']->attendance_date->toDateString().' 09:00:00'],
        'Forgot to clock in on time, was in the building by 9.'
    );

    expect($request->status)->toBe('pending_approval')
        ->and($request->requestType->code)->toBe('attendance_time_change')
        ->and($request->payload['attendance_record_id'])->toBe($f['record']->id)
        ->and($request->payload['original']['check_in'])->toBe($f['record']->check_in->toDateTimeString())
        ->and($request->payload['requested']['check_in'])->toBe($f['record']->attendance_date->toDateString().' 09:00:00');

    // The attendance record itself must not change until approval.
    expect($f['record']->fresh()->check_in->toDateTimeString())->toBe($f['record']->check_in->toDateTimeString());

    $engine = app(ApprovalEngine::class);
    expect($engine->canAct($request->approvalRequest, $f['employeeUser']))->toBeFalse()
        ->and($engine->canAct($request->approvalRequest, $f['managerUser']))->toBeTrue();
});

it('approving a time change request updates the attendance record, recalculates metrics, and preserves who approved it and when', function () {
    $f = attendanceFixture();
    $service = app(EmployeeRequestService::class);

    $request = $service->requestAttendanceTimeChange(
        $f['record'],
        $f['employeeUser'],
        ['check_in' => $f['record']->attendance_date->toDateString().' 09:00:00'],
        'Clocked in late by mistake in the system.'
    );

    $approved = $service->approve($request, $f['managerUser'], 'Confirmed with security log');

    expect($approved->status)->toBe('approved')
        ->and($approved->approved_at)->not->toBeNull();

    $approved->load('approvalRequest.decisions');
    $decision = $approved->approvalRequest->decisions->first();
    expect($decision->decision)->toBe('approved')
        ->and($decision->actor_id)->toBe($f['managerUser']->id)
        ->and($decision->decided_at)->not->toBeNull();

    $record = $f['record']->fresh();
    expect($record->check_in->format('H:i:s'))->toBe('09:00:00')
        ->and($record->late_minutes)->toBe(0) // recalculated: on-time now, not late
        ->and($record->approved_by)->toBe($f['managerUser']->id);

    // The original request itself is never mutated -- still shows what was
    // originally captured at submission time, regardless of the outcome.
    expect($approved->payload['requested']['check_in'])->toBe($f['record']->attendance_date->toDateString().' 09:00:00');
});

it('rejecting a time change request leaves the attendance record completely unchanged', function () {
    $f = attendanceFixture();
    $service = app(EmployeeRequestService::class);
    $originalCheckIn = $f['record']->check_in->toDateTimeString();

    $request = $service->requestAttendanceTimeChange(
        $f['record'],
        $f['employeeUser'],
        ['check_in' => $f['record']->attendance_date->toDateString().' 08:00:00'],
        'Trying to shift my check-in earlier.'
    );

    $rejected = $service->reject($request, $f['managerUser'], 'No supporting evidence for the earlier time.');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('No supporting evidence for the earlier time.');

    $record = $f['record']->fresh();
    expect($record->check_in->toDateTimeString())->toBe($originalCheckIn);

    $rejected->load('approvalRequest.decisions');
    expect($rejected->approvalRequest->decisions->first()->decision)->toBe('rejected');
});

it('refuses a time change request from a user with no relation to the employee', function () {
    $f = attendanceFixture();
    $stranger = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);

    expect(fn () => app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $f['record'],
        $stranger,
        ['check_in' => '2026-01-01 09:00:00'],
    ))->toThrow(RuntimeException::class);
});

it('refuses an unrelated user attempting to approve a pending time change request', function () {
    $f = attendanceFixture();
    $stranger = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $stranger->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $f['record'],
        $f['employeeUser'],
        ['check_in' => '2026-01-01 09:00:00'],
    );

    expect(fn () => app(EmployeeRequestService::class)->approve($request, $stranger))
        ->toThrow(RuntimeException::class, 'not an approver');
});

/**
 * Real defect found during manual testing: AttendanceRecordResource's
 * "Request Time Change" action always submits both requested_check_in AND
 * requested_check_out keys to the service, blank or not (Filament actions
 * dehydrate every schema field regardless of which one the user actually
 * edited). Carbon::parse(null) silently resolves to "now" rather than
 * throwing or staying null, so leaving one field untouched was silently
 * overwriting it with the current timestamp instead of preserving the
 * original value -- confirmed via `Carbon::parse(null)` printing the
 * current time, not erroring.
 */
it('does not corrupt an untouched field with the current time when only one field is actually requested (regression)', function () {
    $f = attendanceFixture();

    // Mirrors exactly what AttendanceRecordResource's action passes: both
    // keys present, but check_out left blank/null because the user only
    // intended to change check_in.
    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange(
        $f['record'],
        $f['employeeUser'],
        ['check_in' => '2026-01-01 09:00:00', 'check_out' => null],
    );

    expect($request->payload['requested']['check_in'])->toBe('2026-01-01 09:00:00')
        ->and($request->payload['requested']['check_out'])->toBe($f['record']->check_out->toDateTimeString())
        ->and($request->payload['requested']['check_out'])->not->toBe(now()->toDateTimeString());
});
