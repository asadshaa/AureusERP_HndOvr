<?php

namespace Webkul\TimeOff\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Security\Models\Role;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;
use Webkul\TimeOff\Models\LeaveType;

/**
 * Section 3 ("IMPLEMENTATION SECTION 3 -- LEAVE / TIME OFF") "Verify/
 * configure" requirement: the Time Off plugin's Employee -> Submit -> Line
 * Manager Review -> HR Review -> Approved/Rejected flow already exists in full
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
        'Sick Leave',
        'Casual Leave',
    ];

    /**
     * Settings copied from the oldest company's own type of the same name
     * (Truck It In's), so every company's Annual / Sick / Casual Leave
     * behaves the same.
     *
     * @var array<int, string>
     */
    private const MIRRORED_SETTINGS = [
        'color',
        'leave_validation_type',
        'requires_allocation',
        'employee_requests',
        'allocation_validation_type',
        'time_type',
        'request_unit',
        'is_active',
        'show_on_dashboard',
        'unpaid',
        'allows_negative',
        'max_allowed_negative',
        'include_public_holidays_in_duration',
        'support_document',
        'create_calendar_meeting',
    ];

    /**
     * Used when no company has the type yet (a fresh install): Truck It In's
     * settings of 2026-10-29.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULT_SETTINGS = [
        'Annual Leave' => ['color' => '#3B82F6'],
        'Sick Leave'   => ['color' => '#EF4444'],
        'Casual Leave' => ['color' => '#F59E0B'],
    ];

    /**
     * The company's own Annual, Sick and Casual Leave types, mirroring the
     * oldest other company's settings, plus their yearly allocation policies
     * (LeaveAllocationPolicy::DEFAULTS: 12 / 8 / 5 days, Annual carries up to
     * 6). Idempotent: an existing type or policy is never changed. Also run
     * for every new company (TimeOffServiceProvider, Company::created).
     */
    public function provisionLeaveTypes(Company $company): void
    {
        foreach (self::LEAVE_TYPE_NAMES as $name) {
            if (LeaveType::query()->where('company_id', $company->id)->where('name', $name)->exists()) {
                continue;
            }

            LeaveType::query()->create(
                ['company_id' => $company->id, 'name' => $name] + $this->settingsFor($name, (int) $company->id),
            );
        }

        LeaveAllocationPolicy::forCompany((int) $company->id);
    }

    /** @return array<string, mixed> */
    private function settingsFor(string $name, int $companyId): array
    {
        $template = LeaveType::query()
            ->where('name', $name)
            ->whereNotNull('company_id')
            ->where('company_id', '!=', $companyId)
            ->orderBy('company_id')
            ->orderBy('id')
            ->first();

        if (! $template) {
            return self::DEFAULT_SETTINGS[$name] + [
                'leave_validation_type'        => 'manager',
                'requires_allocation'          => 'yes',
                'employee_requests'            => 'no',
                'allocation_validation_type'   => 'manager',
                'time_type'                    => 'leave',
                'request_unit'                 => 'day',
                'is_active'                    => true,
                'show_on_dashboard'            => true,
                'unpaid'                       => false,
            ];
        }

        $settings = [];
        foreach (self::MIRRORED_SETTINGS as $attribute) {
            $value = $template->getRawOriginal($attribute);
            if ($value !== null) {
                $settings[$attribute] = $value;
            }
        }

        return $settings;
    }

    public function run(): void
    {
        // Client decision: Time Off's second step is HR (Hr_manager role),
        // not Admin -- Admin/Raza is deliberately kept out of the Time Off
        // approval chain entirely. Known, accepted tradeoff: nothing in
        // ApprovalEngine::canAct() stops someone from approving their own
        // request if they hold the matching role, so an HR Manager
        // submitting their own leave could approve it themselves at this
        // step -- flagged to the client, kept as-is per their explicit
        // instruction rather than silently reintroducing Admin.
        Company::query()->each(function (Company $company): void {
            // Annual / Sick / Casual Leave and their yearly allocation policies (12 / 8 / 5 days).
            $this->provisionLeaveTypes($company);
            $this->provisionWorkflow($company);
        });
    }

    /**
     * The company's leave_request workflow: line manager, then HR
     * (Hr_manager role). Idempotent. Also run for every new company
     * (TimeOffServiceProvider, Company::created).
     */
    public function provisionWorkflow(Company $company): void
    {
        $secondApproverRole = Role::query()->where('name', 'Hr_manager')->where('guard_name', 'web')->first();

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
                    'name'               => 'HR Review',
                    'approver_role_id'   => $secondApproverRole->id,
                    'hierarchy_route'    => null,
                    'required_approvals' => 1,
                ]
            );
        }
    }
}
