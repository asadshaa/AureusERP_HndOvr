<?php

namespace Webkul\Employee\Database\Seeders;

use Database\Seeders\HrRoleSeeder;
use Illuminate\Database\Seeder;
use Webkul\Security\Models\Role;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Section 9 ("IMPLEMENTATION SECTION 9 -- SENSITIVE EMPLOYEE CHANGES"):
 * EmployeeSensitiveChangeService/Employee::synchronizeApprovalState()/the
 * generic ApprovalSubjectSynchronizer (called from inside ApprovalEngine::
 * decide()'s own transaction) already fully implement Change Request ->
 * Approval with Reason -> Approved/Rejected -> Update/No Change -- proven
 * by an existing passing test in HrPlatformTest.php. The one thing missing
 * was a real, seeded ApprovalWorkflow for request_type
 * 'employee_sensitive_change' -- without one, ApprovalEngine::submit()
 * cannot find a workflow to route through and throws, exactly the same gap
 * Section 8 found and fixed for Claims (ClaimsWorkflowSeeder). This mirrors
 * that seeder's pattern: idempotent, non-destructive, one workflow per
 * company.
 *
 * Approver: the "Sensitive-Data Custodian" role (sensitive_data_custodian),
 * not hr_manager -- this role already exists specifically for salary/
 * medical/disciplinary-grade fields (Section 2/6), holds
 * ViewSensitiveEmployeeData so the reviewer can actually see the current
 * and proposed values before deciding, and is the more precise fit than a
 * broad HR-manager bundle for approving exactly this kind of change.
 */
class SensitiveChangeWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        app(HrRoleSeeder::class)->run();

        $custodianRole = Role::query()->where('name', 'sensitive_data_custodian')->where('guard_name', 'web')->first();
        if (! $custodianRole) {
            $this->command?->warn('SensitiveChangeWorkflowSeeder: role "sensitive_data_custodian" was not found -- skipping.');

            return;
        }

        Company::query()->each(function (Company $company) use ($custodianRole): void {
            $workflow = ApprovalWorkflow::query()->firstOrCreate(
                ['company_id' => $company->id, 'request_type' => 'employee_sensitive_change'],
                ['name' => 'Sensitive Employee Data Change Approval', 'is_active' => true]
            );

            if ($workflow->steps()->doesntExist()) {
                ApprovalStep::query()->create([
                    'workflow_id'        => $workflow->id,
                    'sequence'           => 1,
                    'name'               => 'Sensitive Data Review',
                    'approver_role_id'   => $custodianRole->id,
                    'required_approvals' => 1,
                ]);
            }
        });
    }
}
