<?php

namespace Webkul\Support\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;

/**
 * Closes a real gap found while implementing the finance role/permission
 * model: Shield had already generated `*_support_approval::workflow`
 * permission strings for ApprovalWorkflowResource (every Filament
 * Resource gets its permissions generated regardless), but no Policy
 * class existed to interpret them -- so access to approval-workflow
 * *configuration* had no explicit, correct authorization boundary. Roles
 * are wired up server-side purely through the standard model->policy
 * naming convention already used everywhere else in this codebase; no
 * provider registration needed.
 */
class ApprovalWorkflowPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_support_approval::workflow');
    }

    public function view(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('view_support_approval::workflow');
    }

    public function create(User $user): bool
    {
        return $user->can('create_support_approval::workflow');
    }

    public function update(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('update_support_approval::workflow');
    }

    public function delete(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('delete_support_approval::workflow');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_support_approval::workflow');
    }

    public function forceDelete(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('force_delete_support_approval::workflow');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_support_approval::workflow');
    }

    public function restore(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('restore_support_approval::workflow');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_support_approval::workflow');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_support_approval::workflow');
    }
}
