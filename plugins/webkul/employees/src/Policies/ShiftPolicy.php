<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Services\Attendance\ShiftRosterService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/** Shifts are defined by HR of the company (manage attendance + company-wide visibility), in the active company only. */
class ShiftPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return ShiftRosterService::canConfigure($user);
    }

    public function view(User $user, Shift $shift): bool
    {
        return $this->viewAny($user) && CurrentCompany::canAccess((int) $shift->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Shift $shift): bool
    {
        return $this->viewAny($user) && (int) $shift->company_id === (int) CurrentCompany::id($user);
    }

    /** Only a shift no pattern or day change uses; otherwise switch it off. */
    public function delete(User $user, Shift $shift): bool
    {
        return $this->update($user, $shift) && ! $shift->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
