<?php

namespace Webkul\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Job opening approval (Recruitment Stage 2.1): a job must be approved before
 * it can be published to the careers page or any job board. One step, routed
 * to the requesting recruiter's line manager; ApprovalEngine falls back to HR
 * when no line manager resolves. HR can add further steps (e.g. finance for
 * headcount) in the approval workflow screen. Idempotent: never changes an
 * existing workflow or its steps.
 */
class JobOpeningWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => JobPosition::APPROVAL_REQUEST_TYPE],
            ['name' => 'Job Opening Approval', 'is_active' => true]
        );

        if ($workflow->steps()->doesntExist()) {
            ApprovalStep::query()->create([
                'workflow_id'        => $workflow->id,
                'sequence'           => 1,
                'name'               => 'Line Manager Approval',
                'hierarchy_route'    => 'requester_manager',
                'required_approvals' => 1,
            ]);
        }
    }
}
