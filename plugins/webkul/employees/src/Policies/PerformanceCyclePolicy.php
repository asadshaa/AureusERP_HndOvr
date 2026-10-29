<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

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
 *
 * HR Parity 3.2: both also need company-wide HR scope (AppraisalAccess), so
 * a line manager's hr_manage_performance does not let them launch or close
 * company cycles, and cycles are only changed in the active company.
 */
class PerformanceCyclePolicy
{
    use HandlesAuthorization;

    public function __construct(
        protected AppraisalAccess $access,
    ) {}

    /** The list of cycles (no personal data): HR, and read-only hr_view_performance holders. */
    public function viewAny(User $user): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null
            && ($user->can(HrPermissions::ViewPerformance) || $this->access->canViewCompany($user, (int) $companyId));
    }

    public function view(User $user, PerformanceCycle $cycle): bool
    {
        return $this->access->canViewCycle($user, $cycle);
    }

    public function create(User $user): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null && $this->access->canManageCompany($user, (int) $companyId);
    }

    public function update(User $user, PerformanceCycle $cycle): bool
    {
        return $this->access->canManageCycle($user, $cycle);
    }

    /** Only a draft (never launched) cycle is deleted; a launched one is closed instead. */
    public function delete(User $user, PerformanceCycle $cycle): bool
    {
        return $cycle->status === AppraisalKinds::CycleDraft && $this->access->canManageCycle($user, $cycle);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function launch(User $user, PerformanceCycle $cycle): bool
    {
        return $cycle->status === AppraisalKinds::CycleDraft && $this->access->canManageCycle($user, $cycle);
    }

    public function close(User $user, PerformanceCycle $cycle): bool
    {
        return $cycle->isActive() && $this->access->canManageCycle($user, $cycle);
    }
}
