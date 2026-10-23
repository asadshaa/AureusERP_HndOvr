<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;

class StagePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_recruitment_stage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Stage $stage): bool
    {
        return $user->can('view_recruitment_stage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_recruitment_stage');
    }

    /**
     * Determine whether the user can update the model. Shared (company-less)
     * stages are used by every company, so only an administrator may change them.
     */
    public function update(User $user, Stage $stage): bool
    {
        return $user->can('update_recruitment_stage') && $this->mayChangeScope($user, $stage);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Stage $stage): bool
    {
        return $user->can('delete_recruitment_stage') && $this->mayChangeScope($user, $stage);
    }

    /**
     * Determine whether the user can bulk delete. Individual shared stages are
     * still refused by the Stage model's deleting guard.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_recruitment_stage');
    }

    /**
     * Determine whether the user can reorder. Reordering rewrites the order of the
     * shared pipeline too, so it is an administrator action.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_recruitment_stage') && Stage::userManagesSharedStages($user);
    }

    private function mayChangeScope(User $user, Stage $stage): bool
    {
        return ! $stage->isShared() || Stage::userManagesSharedStages($user);
    }
}
