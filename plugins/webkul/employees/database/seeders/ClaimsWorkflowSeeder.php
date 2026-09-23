<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Security\Models\User;
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
 * Approval chain, matching the client-provided org chart exactly:
 *   Level 1 (HR Review)      -> Mehwish, by name, every category.
 *   Level 2 (Line/Dept Head) -> hierarchy_route "requester_manager" (the
 *                               employee's own Manager field) for every
 *                               category except Tech, which uses
 *                               "department_manager" (the department head);
 *                               Real Estate has NO level 2 at all.
 *   Level 3                  -> Raza Afzal, by name, for every category
 *                               except Tech, which uses Haider Navid; Real
 *                               Estate has no level 3.
 *   Level 4                  -> Khurram, by name, for every category except
 *                               Tech, which uses Raza Afzal; Real Estate's
 *                               chain ends at level 2 (Khurram), so it has
 *                               no level 4 either.
 * Each named level is pinned to that specific person's User account via
 * ApprovalStep.approver_user_id -- not a role -- because the org chart
 * assigns named individuals, not job titles, to these steps. Mehwish,
 * Khurram and Haider Navid are placeholder accounts (mehwish@truckitin.com,
 * khurram@truckitin.com, haider.navid@truckitin.com) pending their real
 * emails; Raza Afzal is the existing CEO account. Update the placeholder
 * emails in USER_EMAILS below once the real addresses are known -- no other
 * change is needed, the workflow re-seeds against whichever account matches.
 *
 * VP_FINANCE_THRESHOLD is not part of the client's diagram -- removed. If a
 * value-based extra sign-off is wanted later, add it as a further step via
 * the Approval Workflow admin UI.
 *
 * Debit account and journal are real, existing company records -- the
 * journal must be a PURCHASE-type journal (e.g. "Vendor Bills"), because an
 * approved claim now posts as a real vendor Bill (move_type = IN_INVOICE)
 * via EmployeeRequestService::createAccountingDraft(), not a generic
 * MoveType::ENTRY journal entry. The debit (expense) account is the only
 * account configured here: the credit/payable side of the Bill is resolved
 * automatically at posting time (the vendor Partner's own payable account,
 * or else the company's Accounts Payable account) exactly like any other
 * vendor Bill -- credit_account_id is retained for backward compatibility
 * and admin visibility only, and is not read when posting.
 */
class ClaimsWorkflowSeeder extends Seeder
{
    /** @var array<string, string> */
    private const USER_EMAILS = [
        'mehwish'      => 'mehwish@truckitin.com',
        'raza_afzal'   => 'raza.afzal@truckitin.com',
        'khurram'      => 'khurram@truckitin.com',
        'haider_navid' => 'haider.navid@truckitin.com',
    ];

    /**
     * @var array<string, array{
     *     name: string,
     *     route: 'requester_manager'|'department_manager'|null,
     *     level3: string|null,
     *     level4: string|null,
     *     natures: string,
     * }>
     */
    private const CATEGORIES = [
        'people' => [
            'name'    => 'People',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Payroll, G-Suite, Shell, EOBI, Earned Wage Access, Health Insurance, Munshiana, Income Tax, Zong, BYOD, Medical Bills, Employee Engagement, Team Event',
        ],
        'real_estate' => [
            'name'    => 'Real Estate',
            'route'   => null,
            // Real Estate's chain is only two levels: Mehwish (HR Review),
            // then Khurram directly -- no line manager step, no level 3/4.
            'level2'  => 'khurram',
            'level3'  => null,
            'level4'  => null,
            'natures' => 'Office Rent, Apartment Rent, Utilities, Groceries, Other, Maintenance Charges, Leopard Courier',
        ],
        'digital_marketing' => [
            'name'    => 'Digital Marketing',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'SEO Services, Facebook, Tilism Technologies',
        ],
        'financial_provisions' => [
            'name'    => 'Financial Provisions',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Bank charges, Service Charges, Aspire',
        ],
        'tech' => [
            'name'    => 'Tech',
            'route'   => 'department_manager',
            'level3'  => 'haider_navid',
            'level4'  => 'raza_afzal',
            'natures' => 'Tech tools, Laptop repairs',
        ],
        'professional_services' => [
            'name'    => 'Professional Services',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Tax Consultancy, Stamp Paper, Legal Costs, Legal Consultancy, Audit Agency, First Base, VAPT, Corporate Docs, PSEB',
        ],
        'travel_entertainment' => [
            'name'    => 'Travel and Entertainment',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Travel, Accommodation, Daily Allowance, Fuel, Food, Sports Activity, Farewell',
        ],
        'returns_waivers' => [
            'name'    => 'Returns and Waivers',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Detention',
        ],
        'others' => [
            'name'    => 'Others',
            'route'   => 'requester_manager',
            'level3'  => 'raza_afzal',
            'level4'  => 'khurram',
            'natures' => 'Miscellaneous, Other',
        ],
    ];

    public function run(): void
    {
        $approvers = collect(self::USER_EMAILS)->mapWithKeys(
            fn (string $email, string $key) => [$key => User::query()->where('email', $email)->first()]
        );

        $missing = $approvers->filter(fn (?User $user) => ! $user)->keys();
        if ($missing->isNotEmpty()) {
            $this->command?->warn('ClaimsWorkflowSeeder: missing User accounts for '.$missing->implode(', ').' -- their approval steps will be skipped until those accounts exist.');
        }

        Company::query()->each(function (Company $company) use ($approvers): void {
            // A real vendor Bill (move_type = IN_INVOICE) must post through a
            // PURCHASE-type journal -- see EmployeeRequestService::createAccountingDraft().
            $journal = Journal::query()->where('company_id', $company->id)->where('type', JournalType::PURCHASE)->first();
            $debitAccount = Account::query()->where('code', '600000')->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->first();
            // Still resolved and stored on credit_account_id for backward
            // compatibility / admin visibility, even though posting no
            // longer reads it (the Bill's payable line is auto-resolved --
            // see createAccountingDraft()'s doc comment).
            $creditAccount = Account::query()->where('code', '211000')->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->first();
            $isFinancial = (bool) ($journal && $debitAccount && $creditAccount);
            if (! $isFinancial) {
                $this->command?->warn("ClaimsWorkflowSeeder: company #{$company->id} has no Purchase journal / Expenses (600000) / Accounts Payable (211000) account -- claim types seeded as non-financial (no accounting handoff) until configured via the Employee Request Types admin UI.");
            }

            foreach (self::CATEGORIES as $category => $config) {
                $requestTypeCode = 'claim_'.$category;

                $typeData = [
                    'name'                  => $config['name'],
                    'category'              => $category,
                    'approval_request_type' => $requestTypeCode,
                    'requires_amount'       => true,
                    'requires_document'     => true,
                    'is_active'             => true,
                    'configuration'         => ['expense_natures' => $config['natures']],
                ];

                $existingType = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', $requestTypeCode)->first();
                if ($isFinancial) {
                    $typeData['is_financial'] = true;
                    $typeData['journal_id'] = $journal->id;
                    $typeData['debit_account_id'] = $debitAccount->id;
                    $typeData['credit_account_id'] = $creditAccount->id;
                } elseif (! $existingType) {
                    $typeData['is_financial'] = false;
                }

                $requestType = EmployeeRequestType::query()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $requestTypeCode],
                    $typeData
                );

                $workflow = ApprovalWorkflow::query()->firstOrCreate(
                    ['company_id' => $company->id, 'request_type' => $requestTypeCode],
                    ['name' => $config['name'].' Approval', 'is_active' => true]
                );

                // Rebuild the steps every run so a corrected hierarchy (or a
                // placeholder email finally matching a real account) actually
                // takes effect, rather than silently keeping stale steps.
                $workflow->steps()->delete();

                $sequence = 1;

                $level1 = $approvers->get('mehwish');
                if ($level1) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'HR Review', 'approver_user_id' => $level1->id, 'required_approvals' => 1,
                    ]);
                }

                if ($config['route']) {
                    ApprovalStep::query()->create([
                        'workflow_id'     => $workflow->id, 'sequence' => $sequence++,
                        'name'            => $config['route'] === 'department_manager' ? 'Department Head Review' : 'Line Manager Review',
                        'hierarchy_route' => $config['route'], 'required_approvals' => 1,
                    ]);
                } elseif (! empty($config['level2'])) {
                    // No hierarchy route for this category (e.g. Real Estate) --
                    // level 2 is a specific named approver instead.
                    $level2 = $approvers->get($config['level2']);
                    if ($level2) {
                        ApprovalStep::query()->create([
                            'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                            'name'        => 'Level 2 Approval', 'approver_user_id' => $level2->id, 'required_approvals' => 1,
                        ]);
                    }
                }

                $level3 = $config['level3'] ? $approvers->get($config['level3']) : null;
                if ($level3) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'Level 3 Approval', 'approver_user_id' => $level3->id, 'required_approvals' => 1,
                    ]);
                }

                $level4 = $config['level4'] ? $approvers->get($config['level4']) : null;
                if ($level4) {
                    ApprovalStep::query()->create([
                        'workflow_id' => $workflow->id, 'sequence' => $sequence++,
                        'name'        => 'Level 4 Approval', 'approver_user_id' => $level4->id, 'required_approvals' => 1,
                    ]);
                }
            }
        });
    }
}
