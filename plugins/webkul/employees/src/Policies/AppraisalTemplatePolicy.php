<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Appraisal templates are edited by appraisal HR (hr_manage_performance with
 * company-wide scope), in the active company only; HR auditors may read
 * them. The default template is switched off rather than deleted.
 */
class AppraisalTemplatePolicy
{
    use HandlesAuthorization;

    public function __construct(
        protected AppraisalAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null && $this->access->canViewCompany($user, (int) $companyId);
    }

    public function view(User $user, AppraisalTemplate $template): bool
    {
        return (int) $template->company_id === (int) CurrentCompany::id($user)
            && $this->access->canViewCompany($user, (int) $template->company_id);
    }

    public function create(User $user): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null && $this->access->canManageCompany($user, (int) $companyId);
    }

    public function update(User $user, AppraisalTemplate $template): bool
    {
        return (int) $template->company_id === (int) CurrentCompany::id($user)
            && $this->access->canManageCompany($user, (int) $template->company_id);
    }

    public function delete(User $user, AppraisalTemplate $template): bool
    {
        return $this->update($user, $template) && ! $template->is_default;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
