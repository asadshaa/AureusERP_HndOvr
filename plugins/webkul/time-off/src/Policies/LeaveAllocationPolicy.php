<?php

namespace Webkul\TimeOff\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Models\LeaveAllocation;

class LeaveAllocationPolicy
{
    use HandlesAuthorization;

    public function __construct(protected HrHierarchyService $hierarchy) {}

    /**
     * This one Policy class governs BOTH the HR-facing Management ->
     * Allocations resource (permission family "time_off_allocation") and
     * the employee-facing My Time -> My Allocations self-service resource
     * (permission family "time_off_my::allocation") -- they share the same
     * underlying LeaveAllocation model, so a Laravel Policy is resolved for
     * both regardless of which Filament resource is asking. Every check
     * below must therefore accept EITHER permission family, or else one of
     * the two resources silently loses access even for users who hold the
     * "right" permission for their side of it -- confirmed live: an HR
     * Manager holding view_any_time_off_allocation could not see Management
     * -> Allocations at all, because this method previously checked only
     * the self-service "my::" permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_time_off_allocation') || $user->can('view_any_time_off_my::allocation');
    }

    /**
     * Determine whether the user can view the model.
     *
     * Record-level check added: previously this method checked only the
     * list-level permission string, with no company or hierarchy check at
     * all, so any user holding the permission could view any company's
     * leave allocation by id.
     */
    public function view(User $user, LeaveAllocation $leaveAllocation): bool
    {
        if (! $user->can('view_time_off_allocation') && ! $user->can('view_time_off_my::allocation')) {
            return false;
        }

        return $leaveAllocation->employee && $this->hierarchy->canManage($user, $leaveAllocation->employee);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_time_off_allocation') || $user->can('create_time_off_my::allocation');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LeaveAllocation $leaveAllocation): bool
    {
        if (! $user->can('update_time_off_allocation') && ! $user->can('update_time_off_my::allocation')) {
            return false;
        }

        return $leaveAllocation->employee && $this->hierarchy->canManage($user, $leaveAllocation->employee);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LeaveAllocation $leaveAllocation): bool
    {
        if (! $user->can('delete_time_off_allocation') && ! $user->can('delete_time_off_my::allocation')) {
            return false;
        }

        return $leaveAllocation->employee && $this->hierarchy->canManage($user, $leaveAllocation->employee);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_time_off_allocation') || $user->can('delete_any_time_off_my::allocation');
    }
}
