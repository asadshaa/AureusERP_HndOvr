<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;

/**
 * Section 5 finding: PerformanceReview had no registered Policy at all, so
 * Filament's default (non-strict) authorization allowed ANY user who could
 * view the resource -- which includes every plain employee, per
 * PerformanceReviewResource::canViewAny() -- to edit ANY field on ANY
 * visible review via the generic EditAction, including manager_rating,
 * status, and promotion_recommendation. Confirmed empirically: a plain
 * employee could set their own review to status=completed with a
 * self-written manager_rating and promotion_recommendation, completely
 * bypassing PerformanceService::submitSelfReview()/completeManagerReview()
 * (neither of which the generic EditAction ever calls).
 *
 * update() is deliberately restricted to HR (ManagePerformance) here: the
 * generic edit form has no per-field restriction, so only HR -- who
 * legitimately needs to administer/correct any review -- may use it.
 * Employees and managers act through PerformanceReviewResource's dedicated
 * "Submit Self Review" / "Complete Manager Review" actions instead, which
 * call PerformanceService and enforce exactly who may act, on which
 * review, and at which status.
 */
class PerformanceReviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance)
            || $user->can(HrPermissions::ViewPerformance)
            || $user->employee !== null;
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        if ($user->can(HrPermissions::ManagePerformance) || $user->can(HrPermissions::ViewPerformance) || $user->can(HrPermissions::ViewAllRecords)) {
            return true;
        }

        $employee = $user->employee;

        return $employee !== null
            && ((int) $review->employee_id === (int) $employee->id || (int) $review->reviewer_id === (int) $employee->id);
    }

    public function create(User $user): bool
    {
        // Reviews are only ever created by PerformanceService::launch(); no
        // one creates them by hand through the resource form.
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function update(User $user, PerformanceReview $review): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function delete(User $user, PerformanceReview $review): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance);
    }
}
