<?php

namespace Webkul\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Job offer defaults per company (HR parity 3.6):
 *  - the "job_offer" approval workflow: hiring manager (the engine's
 *    "record_manager" route: the job's manager, else the recruiter's line
 *    manager, else HR), then HR, then the CEO only when the offer's
 *    context says the salary exceeds the company threshold
 *    (ceo_approval = 1, set by JobOfferService);
 *  - a default offer letter template for Pakistan.
 * Idempotent: an existing workflow, its steps or any template are never
 * changed. Run for every company by migration 2026_10_31_400002 and for new
 * companies by RecruitmentServiceProvider (Company::created).
 */
class JobOfferDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        $this->provisionWorkflow($company);
        $this->provisionTemplate($company);
    }

    public function provisionWorkflow(Company $company): void
    {
        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => JobOffer::APPROVAL_REQUEST_TYPE],
            ['name' => 'Job Offer Approval', 'is_active' => true]
        );

        if ($workflow->steps()->exists()) {
            return;
        }

        $ceo = $this->ceoUser($company);
        $adminRoleId = $ceo ? null : $this->adminRoleId();

        foreach ([
            ['name' => 'Hiring Manager Approval', 'hierarchy_route' => 'record_manager'],
            ['name' => 'HR Approval', 'hierarchy_route' => 'hr'],
            [
                'name'             => 'CEO Approval (salary above threshold)',
                'approver_user_id' => $ceo?->id,
                'approver_role_id' => $adminRoleId,
                // Nobody to name: HR decides until someone sets the CEO on the Approval Workflows screen.
                'hierarchy_route'  => $ceo || $adminRoleId ? null : 'hr',
                'conditions'       => [['field' => 'ceo_approval', 'operator' => 'equals', 'value' => '1']],
            ],
        ] as $index => $step) {
            ApprovalStep::query()->create([
                'workflow_id'        => $workflow->id,
                'sequence'           => $index + 1,
                'name'               => $step['name'],
                'hierarchy_route'    => $step['hierarchy_route'] ?? null,
                'approver_user_id'   => $step['approver_user_id'] ?? null,
                'approver_role_id'   => $step['approver_role_id'] ?? null,
                'conditions'         => $step['conditions'] ?? null,
                'required_approvals' => 1,
            ]);
        }
    }

    public function provisionTemplate(Company $company): void
    {
        if (OfferLetterTemplate::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        OfferLetterTemplate::query()->create([
            'company_id'    => $company->id,
            'name'          => 'Standard offer letter (Pakistan)',
            'email_subject' => OfferLetterRenderer::defaultEmailSubject(),
            'body'          => OfferLetterRenderer::defaultTemplateBody(),
            'is_default'    => true,
            'is_active'     => true,
        ]);
    }

    /**
     * The CEO who approves offers above the threshold: the configured
     * address, else a group owner, else the employee titled Chief Executive
     * Officer (the group shares one CEO). HR can change it on the Approval
     * Workflows screen.
     */
    public function ceoUser(Company $company): ?User
    {
        $email = config('recruitment.offers.ceo_approver_email');

        if (filled($email) && ($user = User::query()->where('email', $email)->where('is_active', true)->first())) {
            return $user;
        }

        try {
            if (Permission::query()->where('name', CurrentCompany::MANAGE_MEMBERSHIPS)->exists()) {
                $owner = User::permission(CurrentCompany::MANAGE_MEMBERSHIPS)->where('is_active', true)->orderBy('id')->first();

                if ($owner) {
                    return $owner;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        if (! Schema::hasTable('employees_employees')) {
            return null;
        }

        $ceo = Employee::query()
            ->whereNotNull('user_id')
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('job_title', 'like', '%chief executive%')
                ->orWhere('job_title', 'CEO'))
            ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$company->id])
            ->orderBy('id')
            ->first();

        $user = $ceo?->user;

        return $user instanceof User && $user->is_active ? $user : null;
    }

    protected function adminRoleId(): ?int
    {
        $id = Role::query()->whereRaw('LOWER(name) IN (?, ?)', ['admin', 'super_admin'])->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
