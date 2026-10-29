<?php

namespace Webkul\Employee\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Bound as a scoped (per-request / per-job) service: visibleEmployeeIds() is
 * asked once per policy check (5+ per row on the Employees list), so its
 * result is remembered per user and company for the request and forgotten
 * whenever an employee, department or team is saved or deleted
 * (EmployeeServiceProvider), so a change made earlier in the same request is
 * always seen.
 */
class HrHierarchyService
{
    /** @var array<string, Collection<int, int>> */
    private array $visibleEmployeeIdsCache = [];

    /** @return Collection<int, int> */
    public function visibleEmployeeIds(User $user, int $companyId): Collection
    {
        return $this->visibleEmployeeIdsCache[$user->getKey().':'.$companyId] ??= $this->resolveVisibleEmployeeIds($user, $companyId);
    }

    /**
     * visibleEmployeeIds() over every company the user belongs to, for lists
     * that let a multi-company user look across their companies.
     *
     * @return Collection<int, int>
     */
    public function visibleEmployeeIdsAcrossMemberships(User $user): Collection
    {
        return collect(CurrentCompany::membershipIds($user))
            ->flatMap(fn (int $companyId): Collection => $this->visibleEmployeeIds($user, $companyId))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** Forget remembered visibility (hierarchy data changed). */
    public function flushCache(): void
    {
        $this->visibleEmployeeIdsCache = [];
    }

    /**
     * Whether the user sees every employee of each company they belong to
     * (HR and admins) rather than only their own reporting tree.
     */
    public function seesWholeCompany(User $user): bool
    {
        return $user->can('hr_view_all_records')
            || $user->hasRole([
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
            ]);
    }

    /** @return Collection<int, int> */
    private function resolveVisibleEmployeeIds(User $user, int $companyId): Collection
    {
        $this->assertCompanyAccess($user, $companyId);

        if ($this->seesWholeCompany($user)) {
            return Employee::query()->where('company_id', $companyId)->pluck('id');
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();
        if (! $employee) {
            return collect();
        }

        $visible = collect([$employee->id]);
        $frontier = collect([$employee->id]);
        while ($frontier->isNotEmpty()) {
            $children = Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('parent_id', $frontier)
                ->pluck('id');
            $frontier = $children->diff($visible)->values();
            $visible = $visible->merge($frontier);
        }

        $managedDepartments = Department::query()
            ->where('company_id', $companyId)
            ->where('manager_id', $employee->id)
            ->pluck('id');
        if ($managedDepartments->isNotEmpty()) {
            $departmentIds = Department::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $query) use ($managedDepartments): void {
                    $query->whereIn('id', $managedDepartments);
                    foreach ($managedDepartments as $departmentId) {
                        $query->orWhere('parent_path', 'like', '%/'.$departmentId.'/%');
                    }
                })
                ->pluck('id');
            $visible = $visible->merge(
                Employee::query()->where('company_id', $companyId)->whereIn('department_id', $departmentIds)->pluck('id'),
            );
        }

        $managedTeamIds = Team::query()
            ->where('company_id', $companyId)
            ->where('manager_employee_id', $employee->id)
            ->pluck('id');
        if ($managedTeamIds->isNotEmpty()) {
            $visible = $visible->merge(
                Employee::query()->where('company_id', $companyId)->whereIn('team_id', $managedTeamIds)->pluck('id'),
            );
        }

        return $visible->map(fn ($id): int => (int) $id)->unique()->values();
    }

    public function scopeVisible(Builder $query, User $user, int $companyId): Builder
    {
        return $query
            ->where('company_id', $companyId)
            ->whereIn('id', $this->visibleEmployeeIds($user, $companyId));
    }

    public function assertCanManage(User $user, Employee $employee): void
    {
        if (! $this->canManage($user, $employee)) {
            throw new RuntimeException('The employee is outside this user’s HR hierarchy scope.');
        }
    }

    /**
     * Non-throwing form of assertCanManage(), for use in policy gates that
     * must return a boolean rather than raise. Company access failures and
     * "outside the hierarchy" are both simply "cannot manage" here — the
     * caller does not need to distinguish them to make an authorization
     * decision.
     */
    public function canManage(User $user, Employee $employee): bool
    {
        if (! $employee->company_id) {
            return false;
        }

        try {
            return $this->visibleEmployeeIds($user, (int) $employee->company_id)->contains((int) $employee->id);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The Security-guard user_id's behind every employee this user may
     * manage, for resources keyed by user_id rather than employee_id
     * (e.g. Timesheet). Returns an empty collection under the same
     * conditions visibleEmployeeIds() would.
     *
     * @return Collection<int, int>
     */
    public function visibleUserIds(User $user, int $companyId): Collection
    {
        $employeeIds = $this->visibleEmployeeIds($user, $companyId);

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function assertCompanyAccess(User $user, int $companyId): void
    {
        if (! CurrentCompany::canAccess($companyId, $user)) {
            throw new RuntimeException('The user does not have access to this company.');
        }
    }
}
