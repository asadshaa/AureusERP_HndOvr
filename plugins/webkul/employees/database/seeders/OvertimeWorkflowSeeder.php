<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\Attendance\OvertimeService;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * The "Overtime" request type and its approval workflow per company (HR
 * parity plan 2.2): line manager, then HR (the engine's "hr" route, i.e.
 * ApprovalEngine::resolveHrApproversForCompany()). With no line manager the
 * first step falls back to the same HR set. Same discipline as
 * SelfServiceRequestWorkflowSeeder: idempotent, matched by stable business
 * keys, existing rows are never changed or deleted. Also run for every new
 * company (EmployeeServiceProvider, Company::created) and for the existing
 * companies by migration 2026_10_30_100003.
 */
class OvertimeWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        EmployeeRequestType::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => OvertimeService::RequestCode],
            [
                'name'                  => 'Overtime',
                'category'              => OvertimeService::Category,
                'approval_request_type' => OvertimeService::ApprovalRequestType,
                'is_financial'          => false,
                'requires_amount'       => false,
                'requires_document'     => false,
                'is_active'             => true,
            ]
        );

        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => OvertimeService::ApprovalRequestType],
            ['name' => 'Overtime Approval', 'is_active' => true]
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
