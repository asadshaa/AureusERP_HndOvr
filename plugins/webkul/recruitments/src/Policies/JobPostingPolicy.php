<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Job ads: anyone who can see the job sees its ads; adding channels and
 * confirming ads needs hr_manage_job_postings in the ad's company. Ads are
 * closed, never deleted, so they keep their history.
 */
class JobPostingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_recruitment_job::position');
    }

    public function view(User $user, JobPosting $posting): bool
    {
        return $user->can('view_recruitment_job::position') && $this->companyAccess($user, $posting->company_id);
    }

    public function create(User $user, ?JobPosition $job = null): bool
    {
        return $user->can(HrPermissions::ManageJobPostings)
            && ($job === null || ($job->company_id && $this->companyAccess($user, $job->company_id)));
    }

    public function update(User $user, JobPosting $posting): bool
    {
        return $user->can(HrPermissions::ManageJobPostings) && $this->companyAccess($user, $posting->company_id);
    }

    public function delete(User $user, JobPosting $posting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, JobPosting $posting): bool
    {
        return false;
    }

    public function restore(User $user, JobPosting $posting): bool
    {
        return false;
    }

    protected function companyAccess(User $user, mixed $companyId): bool
    {
        return $companyId && app(ApprovalEngine::class)->userCanAccessCompany($user, (int) $companyId);
    }
}
