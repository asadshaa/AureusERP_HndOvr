<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Security\Traits\HasScopedPermissions;

class CandidatePolicy
{
    use HandlesAuthorization, HasScopedPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_recruitment_candidate');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Candidate $candidate): bool
    {
        return $user->can('view_recruitment_candidate')
            && $this->inMemberCompany($user, $candidate);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_recruitment_candidate');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Candidate $candidate): bool
    {
        if (! $user->can('update_recruitment_candidate')) {
            return false;
        }

        return $this->inActiveCompany($user, $candidate)
            && $this->hasAccess($user, $candidate, 'manager');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Candidate $candidate): bool
    {
        if (! $user->can('delete_recruitment_candidate')) {
            return false;
        }

        return $this->inActiveCompany($user, $candidate)
            && $this->hasAccess($user, $candidate, 'manager');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_recruitment_candidate');
    }

    /** Viewing: the record belongs to one of the user's companies. */
    protected function inMemberCompany(User $user, Candidate $candidate): bool
    {
        return $candidate->company_id !== null && CurrentCompany::canAccess((int) $candidate->company_id, $user);
    }

    /** Changing: the record belongs to the user's active company. */
    protected function inActiveCompany(User $user, Candidate $candidate): bool
    {
        return $candidate->company_id !== null && (int) $candidate->company_id === CurrentCompany::id($user);
    }
}
