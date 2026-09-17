<?php

namespace Webkul\Support\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;

/**
 * See ApprovalWorkflowPolicy's docblock -- same gap, same fix, for the
 * "Approval Queue" resource (ApprovalRequestResource). Note this governs
 * only Filament CRUD visibility of the request record itself; the actual
 * Approve/Reject decision buttons are gated separately and correctly by
 * ApprovalEngine::canAct() inside the resource's own row actions, which
 * this policy does not change.
 */
class ApprovalRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_support_approval::request');
    }

    public function view(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->can('view_support_approval::request');
    }

    public function create(User $user): bool
    {
        return $user->can('create_support_approval::request');
    }

    public function update(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->can('update_support_approval::request');
    }

    public function delete(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->can('delete_support_approval::request');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_support_approval::request');
    }

    public function forceDelete(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->can('force_delete_support_approval::request');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_support_approval::request');
    }

    public function restore(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->can('restore_support_approval::request');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_support_approval::request');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_support_approval::request');
    }
}
