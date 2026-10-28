<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Loan, Salary advance and HR letter request types with their approval
 * workflows, per company (HR parity plan 1.9). Same discipline as
 * AttendanceWorkflowSeeder: idempotent, matched by stable business keys, and
 * existing rows are never changed or deleted.
 *
 * Routing:
 *   Loan           line manager -> HR -> finance (only when a finance approver role has a member in the company)
 *   Salary advance line manager -> HR
 *   HR letter      HR
 * "HR" is the engine's "hr" route: the company's HR approvers
 * (ApprovalEngine::resolveHrApproversForCompany()). With no line manager the
 * first step falls back to the same HR set.
 */
class SelfServiceRequestWorkflowSeeder extends Seeder
{
    /**
     * Finance roles, in order of preference, for the loan's finance step.
     *
     * @var array<int, string>
     */
    public const FinanceApproverRoles = ['cfo', 'vp_finance', 'controller', 'accounting_manager'];

    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        $this->provisionType($company, [
            'code'            => EmployeeRequestKinds::LoanCode,
            'name'            => 'Loan Request',
            'category'        => EmployeeRequestKinds::LoanCategory,
            'requires_amount' => true,
        ], 'Loan Request Approval', [
            ['name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager'],
            ['name' => 'HR Review', 'hierarchy_route' => 'hr'],
        ]);

        $this->provisionType($company, [
            'code'            => EmployeeRequestKinds::SalaryAdvanceCode,
            'name'            => 'Salary Advance',
            'category'        => EmployeeRequestKinds::SalaryAdvanceCategory,
            'requires_amount' => true,
            'configuration'   => [EmployeeRequestKinds::AdvanceSalaryPercentKey => (string) EmployeeRequestKinds::DefaultAdvanceSalaryPercent],
        ], 'Salary Advance Approval', [
            ['name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager'],
            ['name' => 'HR Review', 'hierarchy_route' => 'hr'],
        ]);

        $this->provisionType($company, [
            'code'            => EmployeeRequestKinds::HrLetterCode,
            'name'            => 'HR Letter Request',
            'category'        => EmployeeRequestKinds::HrLetterCategory,
            'requires_amount' => false,
        ], 'HR Letter Approval', [
            ['name' => 'HR Review', 'hierarchy_route' => 'hr'],
        ]);

        $this->provisionLoanFinanceStep($company);
    }

    /** The finance role whose members (in this company) approve loans last, or null when there is none. */
    public function financeApproverRole(Company $company): ?Role
    {
        foreach (self::FinanceApproverRoles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role && CurrentCompany::scopeUsersOf(User::query(), (int) $company->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
                ->exists()) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $type
     * @param  array<int, array{name: string, hierarchy_route: string}>  $steps
     */
    private function provisionType(Company $company, array $type, string $workflowName, array $steps): void
    {
        EmployeeRequestType::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => $type['code']],
            [
                'name'                  => $type['name'],
                'category'              => $type['category'],
                'approval_request_type' => $type['code'],
                'is_financial'          => false,
                'requires_amount'       => $type['requires_amount'],
                'requires_document'     => false,
                'is_active'             => true,
                'configuration'         => $type['configuration'] ?? null,
            ]
        );

        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => $type['code']],
            ['name' => $workflowName, 'is_active' => true]
        );

        if ($workflow->steps()->exists()) {
            return;
        }

        foreach ($steps as $index => $step) {
            ApprovalStep::query()->create([
                'workflow_id'        => $workflow->id,
                'sequence'           => $index + 1,
                'name'               => $step['name'],
                'hierarchy_route'    => $step['hierarchy_route'],
                'required_approvals' => 1,
            ]);
        }
    }

    /**
     * Adds the loan's finance step once the company has a finance approver.
     * Only touches the workflow while it still has exactly the two seeded
     * steps, so a workflow an admin has reshaped is left alone.
     */
    private function provisionLoanFinanceStep(Company $company): void
    {
        $workflow = ApprovalWorkflow::query()
            ->where('company_id', $company->id)
            ->where('request_type', EmployeeRequestKinds::LoanCode)
            ->with('steps')
            ->first();

        if (! $workflow || $workflow->steps->count() !== 2
            || $workflow->steps->pluck('hierarchy_route')->all() !== ['requester_manager', 'hr']) {
            return;
        }

        $role = $this->financeApproverRole($company);

        if (! $role) {
            return;
        }

        ApprovalStep::query()->create([
            'workflow_id'        => $workflow->id,
            'sequence'           => 3,
            'name'               => 'Finance Review',
            'approver_role_id'   => $role->id,
            'required_approvals' => 1,
        ]);
    }
}
