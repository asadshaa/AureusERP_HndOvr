<?php

namespace Webkul\Employee\Services\Appraisals;

use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Who may see and do what on appraisals (enforced by the services, the
 * policy and every page / export / PDF):
 *
 * - Always the active company only; other companies are refused.
 * - HR (hr_manage_performance or hr_view_performance with company-wide HR
 *   scope: hr_view_all_records, an HR role or Admin) sees every appraisal
 *   of the company; with hr_manage_performance HR launches and closes
 *   cycles, calibrates, reassigns reviewers and edits templates. A line
 *   manager's hr_manage_performance alone is not HR.
 * - The employee sees their own appraisal; the manager review, the final
 *   score and the PDF only once the result is shared: when HR closes the
 *   cycle (default) or, if the cycle says so, as soon as the manager submits.
 * - The reviewer (the employee's line manager at launch) and the current
 *   line manager see their reports' appraisals, the self review once it is
 *   submitted. Only the reviewer writes the manager review; when there is
 *   no usable reviewer (none, no login, inactive) the company's HR does.
 * - Nobody reviews or calibrates their own appraisal.
 */
class AppraisalAccess
{
    /** Roles that see the whole company (as HrHierarchyService). */
    public const CompanyWideRoles = [
        'Admin',
        'Super Admin',
        'hr',
        'hr_manager',
        'hr manager',
        'hr_ops_manager',
        'hr ops manager',
        'hr operations manager',
        'hr_administrator',
        'hr administrator',
        'human resources',
        'human resources manager',
    ];

    /** @var array<string, bool> */
    private array $fallbackHrMemo = [];

    public function __construct(
        protected ApprovalEngine $approvals,
    ) {}

    public function hasCompanyWideScope(User $user): bool
    {
        return $user->can(HrPermissions::ViewAllRecords) || $user->hasRole(self::CompanyWideRoles);
    }

    /** HR who runs appraisals in the company: cycles, calibration, reviewers, templates. */
    public function canManageCompany(User $user, int $companyId): bool
    {
        return CurrentCompany::canAccess($companyId, $user)
            && $user->can(HrPermissions::ManagePerformance)
            && $this->hasCompanyWideScope($user);
    }

    /** HR who may read every appraisal of the company (HR auditors included). */
    public function canViewCompany(User $user, int $companyId): bool
    {
        return CurrentCompany::canAccess($companyId, $user)
            && ($user->can(HrPermissions::ManagePerformance) || $user->can(HrPermissions::ViewPerformance))
            && $this->hasCompanyWideScope($user);
    }

    /**
     * HR who stands in for a missing line manager: appraisal HR, or one of
     * the company's HR approvers (ApprovalEngine::resolveHrApproversForCompany(),
     * the people the "no manager" notifications go to).
     */
    public function isFallbackHr(User $user, int $companyId): bool
    {
        if ($this->canManageCompany($user, $companyId)) {
            return true;
        }

        if (! CurrentCompany::canAccess($companyId, $user)
            || (! $user->can(HrPermissions::ViewAllRecords) && ! $user->hasRole(ApprovalEngine::HR_ROLE_NAMES))) {
            return false;
        }

        return $this->fallbackHrMemo[$companyId.':'.$user->getKey()] ??= $this->approvals->isCompanyHrApprover($user, $companyId);
    }

    /** The reviewer's login, when it can act: an active user other than the employee's own. */
    public function managerUser(PerformanceReview $review): ?User
    {
        $user = $review->reviewer?->user;

        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        return (int) $user->getKey() === (int) $review->employee?->user_id ? null : $user;
    }

    /** A line manager can do the manager review (otherwise HR does). */
    public function hasManager(PerformanceReview $review): bool
    {
        return $this->managerUser($review) !== null;
    }

    public function isOwn(User $user, PerformanceReview $review): bool
    {
        return $review->employee?->user_id !== null && (int) $review->employee->user_id === (int) $user->getKey();
    }

    public function isReviewer(User $user, PerformanceReview $review): bool
    {
        return $review->reviewer?->user_id !== null && (int) $review->reviewer->user_id === (int) $user->getKey();
    }

    /** The employee's current line manager. */
    public function isLineManager(User $user, PerformanceReview $review): bool
    {
        $employee = $review->employee;

        if (! $employee instanceof Employee || ! $employee->parent_id) {
            return false;
        }

        $managerUserId = $employee->relationLoaded('parent')
            ? $employee->parent?->user_id
            : Employee::query()->whereKey($employee->parent_id)->value('user_id');

        return $managerUserId !== null && (int) $managerUserId === (int) $user->getKey();
    }

    public function inActiveCompany(User $user, PerformanceReview|PerformanceCycle $record): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null && (int) $record->company_id === (int) $companyId && CurrentCompany::canAccess((int) $companyId, $user);
    }

    public function canView(User $user, PerformanceReview $review): bool
    {
        if (! $this->inActiveCompany($user, $review)) {
            return false;
        }

        if ($this->isOwn($user, $review) || $this->canViewCompany($user, (int) $review->company_id)) {
            return true;
        }

        if ($this->isReviewer($user, $review) || $this->isLineManager($user, $review)) {
            return true;
        }

        return ! $this->hasManager($review) && $this->isFallbackHr($user, (int) $review->company_id);
    }

    /** The employee's own self review always; everyone else once it is submitted. */
    public function canSeeSelfReview(User $user, PerformanceReview $review): bool
    {
        if (! $this->canView($user, $review)) {
            return false;
        }

        return $this->isOwn($user, $review) || $review->submitted_at !== null || $review->isReviewed() || $review->status === AppraisalKinds::StatusManagerReview;
    }

    /** The result is shared with the employee: reviewed, and the cycle is closed or shares results straight away. */
    public function resultsVisibleToEmployee(PerformanceReview $review): bool
    {
        $cycle = $review->cycle;

        return $review->isReviewed() && $cycle instanceof PerformanceCycle
            && ($cycle->isClosed() || $cycle->showsResultsAfterManagerReview());
    }

    /** Manager ratings / comments, the final score and band, the goals. */
    public function canSeeManagerReview(User $user, PerformanceReview $review): bool
    {
        if (! $this->canView($user, $review)) {
            return false;
        }

        if ($this->isOwn($user, $review)) {
            return $this->resultsVisibleToEmployee($review);
        }

        return true;
    }

    /** HR calibration details (reason, original manager score): HR, the reviewer and the line manager, never the employee. */
    public function canSeeCalibration(User $user, PerformanceReview $review): bool
    {
        return $this->canView($user, $review) && ! $this->isOwn($user, $review);
    }

    public function canEditSelfReview(User $user, PerformanceReview $review): bool
    {
        return $review->status === AppraisalKinds::StatusSelfReview
            && $review->cycle?->isActive()
            && $this->inActiveCompany($user, $review)
            && $this->isOwn($user, $review);
    }

    public function canEditManagerReview(User $user, PerformanceReview $review): bool
    {
        if ($review->status !== AppraisalKinds::StatusManagerReview || ! $review->cycle?->isActive()
            || ! $this->inActiveCompany($user, $review) || $this->isOwn($user, $review)) {
            return false;
        }

        $manager = $this->managerUser($review);

        return $manager !== null
            ? (int) $manager->getKey() === (int) $user->getKey()
            : $this->isFallbackHr($user, (int) $review->company_id);
    }

    public function canCalibrate(User $user, PerformanceReview $review): bool
    {
        return $review->status === AppraisalKinds::StatusCompleted
            && $review->acknowledged_at === null
            && $review->cycle?->isActive()
            && $this->inActiveCompany($user, $review)
            && ! $this->isOwn($user, $review)
            && $this->canManageCompany($user, (int) $review->company_id);
    }

    public function canAcknowledge(User $user, PerformanceReview $review): bool
    {
        return $review->status === AppraisalKinds::StatusCompleted
            && $this->inActiveCompany($user, $review)
            && $this->isOwn($user, $review)
            && $this->resultsVisibleToEmployee($review);
    }

    /** Skip a self review, change the reviewer: HR, while the cycle runs. */
    public function canManageReview(User $user, PerformanceReview $review): bool
    {
        return in_array($review->status, [AppraisalKinds::StatusSelfReview, AppraisalKinds::StatusManagerReview], true)
            && $review->cycle?->isActive()
            && $this->inActiveCompany($user, $review)
            && ! $this->isOwn($user, $review)
            && $this->canManageCompany($user, (int) $review->company_id);
    }

    public function canDownloadPdf(User $user, PerformanceReview $review): bool
    {
        return $review->isReviewed() && $this->canSeeManagerReview($user, $review);
    }

    public function canManageCycle(User $user, PerformanceCycle $cycle): bool
    {
        return $this->inActiveCompany($user, $cycle) && $this->canManageCompany($user, (int) $cycle->company_id);
    }

    public function canViewCycle(User $user, PerformanceCycle $cycle): bool
    {
        return $this->inActiveCompany($user, $cycle) && $this->canViewCompany($user, (int) $cycle->company_id);
    }

    /**
     * Every appraisal the user may open in the company (same rules as canView()).
     *
     * @return Builder<PerformanceReview>
     */
    public function visibleQuery(User $user, ?int $companyId = null): Builder
    {
        $companyId ??= CurrentCompany::id($user);
        $query = PerformanceReview::query()->where('employees_performance_reviews.company_id', $companyId ?? 0);

        if ($companyId === null || (int) $companyId !== (int) CurrentCompany::id($user) || ! CurrentCompany::canAccess((int) $companyId, $user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->canViewCompany($user, (int) $companyId)) {
            return $query;
        }

        $myEmployeeIds = $this->myEmployeeIds($user, (int) $companyId);
        $fallback = $this->isFallbackHr($user, (int) $companyId);

        return $query->where(function (Builder $inner) use ($myEmployeeIds, $fallback): void {
            $inner->whereIn('employee_id', $myEmployeeIds ?: [0])
                ->orWhereIn('reviewer_id', $myEmployeeIds ?: [0])
                ->orWhereHas('employee', fn (Builder $employees) => $employees->whereIn('parent_id', $myEmployeeIds ?: [0]));

            if ($fallback) {
                $inner->orWhere(fn (Builder $noManager) => $this->withoutManager($noManager));
            }
        });
    }

    /**
     * The user's own appraisals in the company.
     *
     * @return Builder<PerformanceReview>
     */
    public function myQuery(User $user, int $companyId): Builder
    {
        return $this->visibleQuery($user, $companyId)
            ->whereIn('employee_id', $this->myEmployeeIds($user, $companyId) ?: [0]);
    }

    /**
     * Team appraisals: the user's reviews to do and their direct reports',
     * plus, for HR, the appraisals that have no usable line manager. Never
     * the user's own.
     *
     * @return Builder<PerformanceReview>
     */
    public function teamQuery(User $user, int $companyId): Builder
    {
        $myEmployeeIds = $this->myEmployeeIds($user, $companyId);
        $fallback = $this->isFallbackHr($user, $companyId);

        return $this->visibleQuery($user, $companyId)
            ->whereNotIn('employee_id', $myEmployeeIds ?: [0])
            ->where(function (Builder $inner) use ($myEmployeeIds, $fallback): void {
                $inner->whereIn('reviewer_id', $myEmployeeIds ?: [0])
                    ->orWhereHas('employee', fn (Builder $employees) => $employees->whereIn('parent_id', $myEmployeeIds ?: [0]));

                if ($fallback) {
                    $inner->orWhere(fn (Builder $noManager) => $this->withoutManager($noManager));
                }
            });
    }

    /**
     * Appraisals whose reviewer cannot act: none, removed, no login or an inactive one.
     *
     * @param  Builder<PerformanceReview>  $query
     * @return Builder<PerformanceReview>
     */
    public function withoutManager(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('reviewer_id')
            ->orWhereDoesntHave('reviewer.user', fn (Builder $users) => $users->where('is_active', true)));
    }

    /** @return array<int, int> */
    public function myEmployeeIds(User $user, int $companyId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
