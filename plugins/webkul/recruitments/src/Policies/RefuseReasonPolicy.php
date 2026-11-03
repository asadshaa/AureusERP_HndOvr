<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

class RefuseReasonPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_recruitment_refuse::reason');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RefuseReason $refuseReason): bool
    {
        return $user->can('view_recruitment_refuse::reason');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_recruitment_refuse::reason');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RefuseReason $refuseReason): bool
    {
        return $user->can('update_recruitment_refuse::reason') && $this->managesReason($user, $refuseReason);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RefuseReason $refuseReason): bool
    {
        return $user->can('delete_recruitment_refuse::reason') && $this->managesReason($user, $refuseReason);
    }

    /**
     * A company's own reason is changed in that (active) company; a shared
     * reason (no company) is used by every company, so only by an administrator.
     */
    protected function managesReason(User $user, RefuseReason $refuseReason): bool
    {
        return $refuseReason->company_id === null
            ? Stage::userManagesSharedStages($user)
            : (int) $refuseReason->company_id === CurrentCompany::id($user);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_recruitment_refuse::reason');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_recruitment_refuse::reason');
    }
}
