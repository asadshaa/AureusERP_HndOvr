<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Who may run the HR reports (Employees -> Reportings) and on which
 * employees: hr_view_reports, a company the user belongs to, and the user's
 * HR scope (HrHierarchyService: HR with hr_view_all_records sees the whole
 * company, anyone else only the people they look after). A department
 * filter takes its sub-departments too.
 */
class HrReportScope
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    public static function canView(?User $user): bool
    {
        return $user !== null && $user->can(HrPermissions::ViewReports);
    }

    /** @throws AuthorizationException */
    public function authorize(User $user, int $companyId): void
    {
        if (! static::canView($user)) {
            throw new AuthorizationException('You may not view HR reports.');
        }

        if (! CurrentCompany::canAccess($companyId, $user)) {
            throw new AuthorizationException('You do not belong to that company.');
        }
    }

    /**
     * Limit an employees query to the company, the user's HR scope and the
     * department (with its sub-departments). No extra query for HR who see
     * the whole company; the department is a sub-query.
     *
     * @throws AuthorizationException
     */
    public function employees(Builder $query, User $user, int $companyId, ?int $departmentId = null): Builder
    {
        $this->authorize($user, $companyId);

        $table = $query->getModel()->getTable();

        return $query
            ->where($table.'.company_id', $companyId)
            ->unless($this->hierarchy->seesWholeCompany($user), fn (Builder $query) => $query->whereIn($table.'.id', $this->hierarchy->visibleEmployeeIds($user, $companyId)->all()))
            ->when($departmentId, fn (Builder $query) => $query->whereIn($table.'.department_id', Department::query()
                ->select('id')
                ->where('company_id', $companyId)
                ->where(fn (Builder $departments) => $departments->whereKey($departmentId)->orWhere('parent_path', 'like', '%/'.$departmentId.'/%'))));
    }

    /**
     * The report header: company, department filter, when and by whom.
     *
     * @return array{company: array{id: int, name: string}, department: ?string, generated_at: CarbonImmutable, generated_by: string}
     */
    public function header(User $user, int $companyId, ?int $departmentId): array
    {
        $department = $departmentId ? Department::query()->where('company_id', $companyId)->whereKey($departmentId)->first(['id', 'name', 'complete_name']) : null;

        return [
            'company'      => ['id' => $companyId, 'name' => (string) Company::query()->whereKey($companyId)->value('name')],
            'department'   => $department ? (string) ($department->complete_name ?: $department->name) : null,
            'generated_at' => static::now(),
            'generated_by' => (string) $user->name,
        ];
    }

    public static function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(static::timezone());
    }
}
