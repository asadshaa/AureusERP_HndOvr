<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
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
 * update() is deliberately restricted to HR here: the generic edit form has
 * no per-field restriction, so only HR -- who legitimately needs to
 * administer/correct any review -- may use it. Employees and managers act
 * through the appraisal page (PerformanceService), which enforces exactly
 * who may act, on which review, and at which status.
 *
 * HR Parity 3.2: "HR" is hr_manage_performance WITH company-wide HR scope
 * (AppraisalAccess) -- a line manager's role bundle also holds
 * hr_manage_performance, which used to let them read and edit every review
 * of the company. Viewing follows AppraisalAccess::canView() (own, reports,
 * HR of the active company).
 */
class PerformanceReviewPolicy
{
    use HandlesAuthorization;

    public function __construct(
        protected AppraisalAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManagePerformance)
            || $user->can(HrPermissions::ViewPerformance)
            || $user->employee !== null;
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        return $this->access->canView($user, $review);
    }

    public function create(User $user): bool
    {
        // Reviews are only ever created by PerformanceService::launch(); no
        // one creates them by hand through the resource form.
        return false;
    }

    public function update(User $user, PerformanceReview $review): bool
    {
        return $this->access->inActiveCompany($user, $review)
            && ! $this->access->isOwn($user, $review)
            && $this->access->canManageCompany($user, (int) $review->company_id);
    }

    public function delete(User $user, PerformanceReview $review): bool
    {
        return $this->update($user, $review);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function selfReview(User $user, PerformanceReview $review): bool
    {
        return $this->access->canEditSelfReview($user, $review);
    }

    public function managerReview(User $user, PerformanceReview $review): bool
    {
        return $this->access->canEditManagerReview($user, $review);
    }

    public function calibrate(User $user, PerformanceReview $review): bool
    {
        return $this->access->canCalibrate($user, $review);
    }

    public function acknowledge(User $user, PerformanceReview $review): bool
    {
        return $this->access->canAcknowledge($user, $review);
    }

    public function downloadPdf(User $user, PerformanceReview $review): bool
    {
        return $this->access->canDownloadPdf($user, $review);
    }
}
