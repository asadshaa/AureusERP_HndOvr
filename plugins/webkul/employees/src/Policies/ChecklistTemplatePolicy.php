<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/** Checklist templates are edited by HR (hr_manage_onboarding), in the active company only. */
class ChecklistTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManageChecklists);
    }

    public function view(User $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user) && CurrentCompany::canAccess((int) $template->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user) && (int) $template->company_id === (int) CurrentCompany::id($user);
    }

    /** Running checklists keep their copied tasks; the default template is switched off rather than deleted. */
    public function delete(User $user, ChecklistTemplate $template): bool
    {
        return $this->update($user, $template) && ! $template->is_default;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
