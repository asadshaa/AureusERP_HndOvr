<?php

namespace Webkul\TimeOff\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

/**
 * Section 3 ("IMPLEMENTATION SECTION 3 -- LEAVE / TIME OFF") "Verify/
 * configure" requirement: the Time Off plugin's Employee -> Submit -> Line
 * Manager Review -> Approved/Rejected flow already exists in full
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
        Company::query()->each(function (Company $company): void {
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

            if ($workflow->steps()->doesntExist()) {
                ApprovalStep::query()->create([
                    'workflow_id'         => $workflow->id,
                    'sequence'            => 1,
                    'name'                => 'Line Manager Review',
                    'hierarchy_route'     => 'requester_manager',
                    'required_approvals'  => 1,
                ]);
            }
        });
    }
}
