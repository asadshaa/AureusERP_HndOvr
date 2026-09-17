<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;

/**
 * Section 5 finding: PerformanceCycle also had no registered Policy, so a
 * user holding only the read-only-intended ViewPerformance permission could
 * still edit/delete cycles (and, per the resource's own custom "launch"
 * action having no authorization of its own either, could even launch one)
 * via the generic table actions -- Filament's default authorization allows
 * by default when no policy exists. create/update/delete/launch are all
 * restricted to ManagePerformance here; ViewPerformance stays read-only, as
 * the permission's own name and Section 2's established
 * ManageX/ViewX split convention (AttendanceRecord, PerformanceReview,
 * EmployeeRequestType) already implies.
 */
class PerformanceCyclePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance) || $user->can(HrPermissions::ViewPerformance);
    }

    public function view(User $user, PerformanceCycle $cycle): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function update(User $user, PerformanceCycle $cycle): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function delete(User $user, PerformanceCycle $cycle): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }
}
