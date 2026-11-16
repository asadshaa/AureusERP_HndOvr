<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\ExpenseHeadsUp;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * The "Expense heads-up" request type and its approval workflow per company:
 * line manager, then HR. No finance step, because no money moves. Idempotent
 * and additive, same discipline as OvertimeWorkflowSeeder: existing rows are
 * never changed or deleted. Run for every new company (EmployeeServiceProvider,
 * Company::created) and for existing companies by migration.
 */
class ExpenseHeadsUpWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        EmployeeRequestType::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => ExpenseHeadsUp::Code],
            [
                'name'                  => 'Expense heads-up',
                'category'              => ExpenseHeadsUp::Category,
                'approval_request_type' => ExpenseHeadsUp::ApprovalRequestType,
                'is_financial'          => false,
                'requires_amount'       => false,
                'requires_document'     => false,
                'is_active'             => true,
                'configuration'         => [
                    'expense_natures'                       => ExpenseHeadsUp::GenericNatures,
                    ExpenseHeadsUp::OverEstimatePercentKey  => ExpenseHeadsUp::DefaultOverEstimatePercent,
                ],
            ]
        );

        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => ExpenseHeadsUp::ApprovalRequestType],
            ['name' => 'Expense Heads-up Approval', 'is_active' => true]
        );

        if ($workflow->steps()->exists()) {
            return;
        }

        foreach ([
            ['name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager'],
            ['name' => 'HR Review', 'hierarchy_route' => 'hr'],
        ] as $index => $step) {
            ApprovalStep::query()->create([
                'workflow_id'        => $workflow->id,
                'sequence'           => $index + 1,
                'name'               => $step['name'],
                'hierarchy_route'    => $step['hierarchy_route'],
                'required_approvals' => 1,
            ]);
        }
    }
}
