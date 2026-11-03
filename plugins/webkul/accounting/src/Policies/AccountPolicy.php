<?php

namespace Webkul\Accounting\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Webkul\Accounting\Models\Account;
use Webkul\Security\Models\User;

class AccountPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_accounting_account');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Account $account): bool
    {
        return $user->can('view_accounting_account');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_accounting_account');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Account $account): Response|bool
    {
        if (! $user->can('update_accounting_account')) {
            return false;
        }

        return $this->allowsSharedAccountChange($user, $account, 'change');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Account $account): Response|bool
    {
        if (! $user->can('delete_accounting_account')) {
            return false;
        }

        return $this->allowsSharedAccountChange($user, $account, 'delete');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_accounting_account');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Account $account): bool
    {
        return $user->can('force_delete_accounting_account');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_accounting_account');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Account $account): bool
    {
        return $user->can('restore_accounting_account');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_accounting_account');
    }

    /**
     * A shared account (linked to several companies) may only be changed or
     * deleted by someone who belongs to every linked company, or a group owner.
     */
    protected function allowsSharedAccountChange(User $user, Account $account, string $verb): Response
    {
        if ($account->companiesInaccessibleTo($user) === []) {
            return Response::allow();
        }

        return Response::deny("This account is shared with companies you do not belong to; only a user of every linked company (or a group owner) can {$verb} it.");
    }
}
