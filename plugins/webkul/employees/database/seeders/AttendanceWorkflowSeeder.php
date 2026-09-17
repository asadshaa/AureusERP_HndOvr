<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Section 4 ("IMPLEMENTATION SECTION 4 -- ATTENDANCE") TIME CHANGE REQUEST
 * workflow: Employee -> Time Change Request -> Line Manager Approval ->
 * Approved/Rejected. This reuses the existing EmployeeRequest/
 * EmployeeRequestType/ApprovalEngine machinery (already used for financial
 * HR requests) rather than a new parallel request/approval system -- an
 * "attendance_time_change" EmployeeRequestType plus a one-step, line-manager
 * ApprovalWorkflow is all that was missing to make that existing machinery
 * usable for attendance corrections. Mirrors LeaveWorkflowSeeder's exact
 * pattern and provisioning discipline (idempotent, never deletes/overwrites
 * existing rows, matched by stable business keys).
 */
class AttendanceWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            EmployeeRequestType::query()->firstOrCreate(
                ['company_id' => $company->id, 'code' => 'attendance_time_change'],
                [
                    'name'                  => 'Attendance Time Change',
                    'category'              => 'attendance_correction',
                    'approval_request_type' => 'attendance_time_change',
                    'is_financial'          => false,
                    'requires_amount'       => false,
                    'requires_document'     => false,
                    'is_active'             => true,
                ]
            );

            $workflow = ApprovalWorkflow::query()->firstOrCreate(
                ['company_id' => $company->id, 'request_type' => 'attendance_time_change'],
                ['name' => 'Attendance Time Change Approval', 'is_active' => true]
            );

            if ($workflow->steps()->doesntExist()) {
                ApprovalStep::query()->create([
                    'workflow_id'        => $workflow->id,
                    'sequence'           => 1,
                    'name'               => 'Line Manager Review',
                    'hierarchy_route'    => 'requester_manager',
                    'required_approvals' => 1,
                ]);
            }
        });
    }
}
