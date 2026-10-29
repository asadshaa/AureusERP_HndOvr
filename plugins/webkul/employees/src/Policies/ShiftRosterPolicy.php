<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Employee\Services\Attendance\ShiftRosterService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/** Roster patterns are defined by HR of the company, in the active company only. */
class ShiftRosterPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return ShiftRosterService::canConfigure($user);
    }

    public function view(User $user, ShiftRoster $roster): bool
    {
        return $this->viewAny($user) && CurrentCompany::canAccess((int) $roster->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ShiftRoster $roster): bool
    {
        return $this->viewAny($user) && (int) $roster->company_id === (int) CurrentCompany::id($user);
    }

    /** Only a pattern never assigned; otherwise switch it off. */
    public function delete(User $user, ShiftRoster $roster): bool
    {
        return $this->update($user, $roster) && ! $roster->assignments()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
