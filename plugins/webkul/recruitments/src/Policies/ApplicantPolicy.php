<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Security\Traits\HasScopedPermissions;

class ApplicantPolicy
{
    use HandlesAuthorization, HasScopedPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_recruitment_applicant');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Applicant $applicant): bool
    {
        return $user->can('view_recruitment_applicant')
            && $this->inMemberCompany($user, $applicant);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_recruitment_applicant');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Applicant $applicant): bool
    {
        if (! $user->can('update_recruitment_applicant')) {
            return false;
        }

        return $this->inActiveCompany($user, $applicant)
            && $this->hasAccess($user, $applicant, 'recruiter');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Applicant $applicant): bool
    {
        if (! $user->can('delete_recruitment_applicant')) {
            return false;
        }

        return $this->inActiveCompany($user, $applicant)
            && $this->hasAccess($user, $applicant, 'recruiter');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_recruitment_applicant');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Applicant $applicant): bool
    {
        if (! $user->can('force_delete_recruitment_applicant')) {
            return false;
        }

        return $this->inActiveCompany($user, $applicant)
            && $this->hasAccess($user, $applicant, 'recruiter');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_recruitment_applicant');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Applicant $applicant): bool
    {
        if (! $user->can('restore_recruitment_applicant')) {
            return false;
        }

        return $this->inActiveCompany($user, $applicant)
            && $this->hasAccess($user, $applicant, 'recruiter');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_recruitment_applicant');
    }

    /** Viewing: the record belongs to one of the user's companies. */
    protected function inMemberCompany(User $user, Applicant $applicant): bool
    {
        return $applicant->company_id !== null && CurrentCompany::canAccess((int) $applicant->company_id, $user);
    }

    /** Changing: the record belongs to the user's active company. */
    protected function inActiveCompany(User $user, Applicant $applicant): bool
    {
        return $applicant->company_id !== null && (int) $applicant->company_id === CurrentCompany::id($user);
    }
}
