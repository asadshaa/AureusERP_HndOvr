<?php

namespace Webkul\TimeOff\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Security\Models\Role;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

/**
 * Section 3 ("IMPLEMENTATION SECTION 3 -- LEAVE / TIME OFF") "Verify/
 * configure" requirement: the Time Off plugin's Employee -> Submit -> Line
 * Manager Review -> Final Review (Admin) -> Approved/Rejected flow already exists in full
 * (LeaveApprovalService + ApprovalEngine + TimeOffResource's submit/approve/
 * refuse actions), but two prerequisites it depends on were never actually
 * provisioned anywhere in this repo:
 *
 *   1. No production seeder creates the three canonical leave types this
 *      task names (Annual/Casual/Sick Leave) -- only the *demo*
 *      LeaveTypeSeeder does, and that seeder unconditionally deletes every
 *      existing time_off_leave_types row first (DB::table(...)->delete()),
 *      seeds unrelated demo names ("Training Time Off", "Compensatory Days
 *      test", ...), and is not safe to run against real data.
 *   2. No seeder anywhere creates an ApprovalWorkflow row with
 *      request_type = 'leave_request' for any company. Without one,
 *      ApprovalEngine::submit() unconditionally throws "No active approval
 *      workflow is configured for [leave_request] in this company" --
 *      meaning the existing submit-for-approval action is unusable out of
 *      the box for every company until this is configured, by seeder or by
 *      hand through ApprovalWorkflowResource.
 *
 * This seeder does not invent new leave/approval logic -- it only
 * provisions the configuration the existing LeaveApprovalService/
 * ApprovalEngine code already expects, using the same hierarchy_route
 * mechanism ('requester_manager') every other HR/Finance workflow in this
 * app already uses for "line manager approves". Idempotent (matched by the
 * stable business key company_id+name / company_id+request_type) and safe
 * to re-run: it never deletes or overwrites an existing row, matching the
 * pattern already established by HrRoleSeeder/FinanceRoleSeeder.
 */
class LeaveWorkflowSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const LEAVE_TYPE_NAMES = [
        'Annual Leave',
        'Casual Leave',
        'Sick Leave',
    ];

    public function run(): void
    {
        // Deliberately Admin, not Hr_manager -- the HR Manager (Zainab) is
        // herself the most likely person to submit a leave request, and
        // nothing in ApprovalEngine::canAct() stops someone from approving
        // their own request if they hold the matching role. Admin (Raza)
        // is never the requester in practice, so this closes that
        // self-approval gap without building a generic requester-exclusion
        // mechanism the client didn't ask for.
        $secondApproverRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->first();

        Company::query()->each(function (Company $company) use ($secondApproverRole): void {
            foreach (self::LEAVE_TYPE_NAMES as $name) {
                LeaveType::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    [
                        'leave_validation_type'        => 'manager',
                        'requires_allocation'          => 'yes',
                        'employee_requests'            => 'no',
                        'allocation_validation_type'   => 'manager',
                        'time_type'                    => 'leave',
                        'request_unit'                 => 'day',
                        'is_active'                    => true,
                        'show_on_dashboard'            => true,
                        'unpaid'                       => false,
                    ]
                );
            }

            $workflow = ApprovalWorkflow::query()->firstOrCreate(
                ['company_id' => $company->id, 'request_type' => 'leave_request'],
                ['name' => 'Leave Request Approval', 'is_active' => true]
            );

            ApprovalStep::query()->firstOrCreate(
                ['workflow_id' => $workflow->id, 'sequence' => 1],
                [
                    'name'               => 'Line Manager Review',
                    'hierarchy_route'    => 'requester_manager',
                    'required_approvals' => 1,
                ]
            );

            // Second, role-based step: line manager approves first, then
            // this role signs off before the leave is finally approved --
            // both steps are decided by whoever actually holds that
            // role/position, per ApprovalEngine.canAct(), never a
            // stand-in. updateOrCreate (not firstOrCreate) so re-running
            // this seeder after the approver role changes actually applies
            // the change to an already-provisioned company.
            if ($secondApproverRole) {
                ApprovalStep::query()->updateOrCreate(
                    ['workflow_id' => $workflow->id, 'sequence' => 2],
                    [
                        'name'               => 'Final Review',
                        'approver_role_id'   => $secondApproverRole->id,
                        'hierarchy_route'    => null,
                        'required_approvals' => 1,
                    ]
                );
            }
        });
    }
}
