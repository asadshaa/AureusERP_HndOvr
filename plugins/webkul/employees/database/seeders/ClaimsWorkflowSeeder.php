<?php

namespace Webkul\Employee\Database\Seeders;

use Database\Seeders\FinanceRoleSeeder;
use Database\Seeders\HrRoleSeeder;
use Illuminate\Database\Seeder;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Security\Models\Role;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Section 8 ("IMPLEMENTATION SECTION 8 -- CLAIMS & REIMBURSEMENTS"): reuses
 * the existing EmployeeRequestType/EmployeeRequest/ApprovalWorkflow/
 * ApprovalStep/EmployeeRequestService machinery end to end (Employee Request
 * -> ApprovalEngine -> accounting draft on approval) -- no parallel Claims
 * module. One EmployeeRequestType + one ApprovalWorkflow per documented
 * claim category (Travel & Entertainment, Professional Services, Tech,
 * Digital Marketing, Returns and Waivers, Financial Provisions, Others,
 * People, Real Estate), since Tech and Real Estate route differently from
 * the rest (see ROUTES below) and ApprovalWorkflow.request_type has to be
 * unique per routing shape, not shared blindly across all nine.
 *
 * IMPORTANT -- what this seeder can and cannot configure:
 *
 * The documented routing names four specific individuals (Mehwish, Raza
 * Afzal, Khurram, Haider Navid) as Approval Levels 1/3/4 (Level 2 for Tech)
 * across every category. NONE of these four exist as Employee/User records
 * in this database (confirmed by direct query -- full table scan, plus
 * case-insensitive partial-name matching, zero results for all four). Per
 * the task's own explicit instruction ("If a person does not exist, do NOT
 * create a fake account automatically. Report missing workflow actors."),
 * this seeder does NOT fabricate approvers for them. It seeds only the
 * steps that resolve to something real today:
 *   - HR Review           -> role "hr_manager" (real, seeded by HrRoleSeeder)
 *   - Line Manager review -> hierarchy_route "requester_manager" (real,
 *                            Employee.parent_id -- the documented "Level 2"
 *                            for every category except Tech)
 *   - Department Head rev.-> hierarchy_route "department_manager" (real,
 *                            Department.manager_id -- Tech's "Level 2")
 *   - Finance Final Proc. -> role "controller" (real, seeded by FinanceRoleSeeder)
 *   - VP Finance oversight-> role "vp_finance" (real), gated by an amount
 *                            condition so it does NOT run on every claim --
 *                            see VP_FINANCE_THRESHOLD below.
 * The named-individual levels are simply omitted from the seeded workflow
 * (ApprovalStep requires exactly one of approver_user_id/approver_role_id/
 * hierarchy_route -- there is no way to create a step for an unresolved
 * person without fabricating one). Once real User accounts exist for these
 * four people, add their steps through the existing Approval Workflow admin
 * UI (Filament ApprovalWorkflowResource) -- no code change is needed for
 * that; the architecture already supports pinning a step to one specific
 * named user via approver_user_id. Real Estate's documented routing is
 * Level 1 = Mehwish, Level 2 = Khurram only -- BOTH unresolved -- so its
 * seeded workflow has no business-hierarchy step at all, only HR Review and
 * Finance Final Processing/VP Finance oversight.
 *
 * VP_FINANCE_THRESHOLD is a placeholder, not a confirmed business policy --
 * flagged in the implementation report; adjust via the admin UI (edit the
 * "VP Finance Oversight" step's Conditions) once Finance leadership confirms
 * the real amount, no code change needed either way.
 *
 * Debit/credit accounts and journal are real, existing company records
 * (standard double-entry: Dr Expenses, Cr Accounts Payable -- a draft
 * liability until Accounting actually pays it, not a cash/bank posting),
 * not a fabricated or category-specific chart-of-accounts assumption.
 */
class ClaimsWorkflowSeeder extends Seeder
{
    private const VP_FINANCE_THRESHOLD = '100000.0000';

    /** @var array<string, array{name: string, route: 'requester_manager'|'department_manager'|null, natures: string}> */
    private const CATEGORIES = [
        'travel_entertainment'  => ['name' => 'Travel & Entertainment', 'route' => 'requester_manager', 'natures' => 'Airfare, Hotel, Meals, Client Entertainment, Local Transport'],
        'professional_services' => ['name' => 'Professional Services', 'route' => 'requester_manager', 'natures' => 'Legal Fees, Consulting Fees, Audit Fees, Training/Certification'],
        'tech'                  => ['name' => 'Tech', 'route' => 'department_manager', 'natures' => 'Software License, Hardware, Cloud Hosting, IT Support'],
        'digital_marketing'     => ['name' => 'Digital Marketing', 'route' => 'requester_manager', 'natures' => 'Advertising Spend, Content/Design, Marketing Tools, Sponsorship'],
        'returns_waivers'       => ['name' => 'Returns and Waivers', 'route' => 'requester_manager', 'natures' => 'Customer Refund, Fee Waiver, Goodwill Credit'],
        'financial_provisions'  => ['name' => 'Financial Provisions', 'route' => 'requester_manager', 'natures' => 'Bad Debt Provision, Contingency Provision, Other Provision'],
        'others'                => ['name' => 'Others', 'route' => 'requester_manager', 'natures' => 'Miscellaneous, Other'],
        'people'                => ['name' => 'People', 'route' => 'requester_manager', 'natures' => 'Team Event, Gift, Training, Other'],
        'real_estate'           => ['name' => 'Real Estate', 'route' => null, 'natures' => 'Rent, Maintenance, Utilities, Security Deposit'],
    ];

    public function run(): void
    {
        app(HrRoleSeeder::class)->run();
        app(FinanceRoleSeeder::class)->run();

        $hrReviewRole = Role::query()->where('name', 'hr_manager')->where('guard_name', 'web')->first();
        $financeRole = Role::query()->where('name', 'controller')->where('guard_name', 'web')->first();
        $vpFinanceRole = Role::query()->where('name', 'vp_finance')->where('guard_name', 'web')->first();

        if (! $hrReviewRole || ! $financeRole || ! $vpFinanceRole) {
            $this->command?->warn('ClaimsWorkflowSeeder: one or more expected roles (hr_manager/controller/vp_finance) were not found -- skipping the steps that depend on them.');
        }

        Company::query()->each(function (Company $company) use ($hrReviewRole, $financeRole, $vpFinanceRole): void {
            $journal = Journal::query()->where('company_id', $company->id)->where('type', 'general')->first();
            $debitAccount = Account::query()->where('code', '600000')->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->first();
            $creditAccount = Account::query()->where('code', '211000')->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->first();
            $isFinancial = (bool) ($journal && $debitAccount && $creditAccount);
            if (! $isFinancial) {
                $this->command?->warn("ClaimsWorkflowSeeder: company #{$company->id} has no general journal / Expenses (600000) / Accounts Payable (211000) account -- claim types seeded as non-financial (no accounting handoff) until configured via the Employee Request Types admin UI.");
            }

            foreach (self::CATEGORIES as $category => $config) {
                $requestTypeCode = 'claim_'.$category;

                $requestType = EmployeeRequestType::query()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $requestTypeCode],
                    [
                        'name'                  => $config['name'].' Claim',
                        'category'              => $category,
                        'approval_request_type' => $requestTypeCode,
                        'is_financial'          => $isFinancial,
                        'requires_amount'       => true,
                        'requires_document'     => true,
                        'is_active'             => true,
                        'journal_id'            => $journal?->id,
                        'debit_account_id'      => $debitAccount?->id,
                        'credit_account_id'     => $creditAccount?->id,
                        'configuration'         => ['expense_natures' => $config['natures']],
                    ]
                );

                $workflow = ApprovalWorkflow::query()->firstOrCreate(
                    ['company_id' => $company->id, 'request_type' => $requestTypeCode],
                    ['name' => $config['name'].' Claim Approval', 'is_active' => true]
                );

                if ($workflow->steps()->exists()) {
                    continue;
                }

                $sequence = 1;
                if ($hrReviewRole) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'HR Review', 'approver_role_id' => $hrReviewRole->id, 'required_approvals' => 1,
                    ]);
                }
                if ($config['route']) {
                    ApprovalStep::query()->create([
                        'workflow_id'     => $workflow->id, 'sequence' => $sequence++,
                        'name'            => $config['route'] === 'department_manager' ? 'Department Head Review' : 'Line Manager Review',
                        'hierarchy_route' => $config['route'], 'required_approvals' => 1,
                    ]);
                }
                if ($financeRole) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'Finance Final Processing', 'approver_role_id' => $financeRole->id, 'required_approvals' => 1,
                    ]);
                }
                if ($vpFinanceRole) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'VP Finance Oversight', 'approver_role_id' => $vpFinanceRole->id, 'required_approvals' => 1,
                        'conditions'  => [['field' => 'amount', 'operator' => 'gte', 'value' => self::VP_FINANCE_THRESHOLD]],
                    ]);
                }
            }
        });
    }
}
