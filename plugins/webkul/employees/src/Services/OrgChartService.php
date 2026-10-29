<?php

namespace Webkul\Employee\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Employees -> Org Chart (HR Parity 3.1): the reporting lines of one company
 * (employee parent_id) as a tree, with departments for filtering.
 *
 * Who sees whom follows HrHierarchyService: HR (and admins) and the group
 * owner see the whole company; anyone else sees what HrHierarchyService lets
 * them manage (their subtree, managed departments and teams) plus their own
 * chain upwards and their peers (people with the same manager). Only current
 * employees appear (active, not archived, leaving date not passed).
 *
 * Built from two queries (employees with their photo and job, departments)
 * plus HrHierarchyService's own lookups; the tree itself is assembled here.
 * A person can be opened only when the Employees screen would let the user
 * view them (view_employee_employee and inside their HR scope).
 */
class OrgChartService
{
    /** Muted initials backgrounds, picked by employee id. */
    private const COLORS = ['#2563eb', '#0891b2', '#059669', '#65a30d', '#d97706', '#dc2626', '#db2777', '#7c3aed', '#4f46e5', '#0d9488'];

    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    /**
     * @return array{
     *     company_id: int,
     *     scope: 'company'|'hierarchy',
     *     self_id: ?int,
     *     total: int,
     *     roots: array<int, int>,
     *     order: array<int, int>,
     *     nodes: array<int, array{id: int, name: string, title: ?string, dept_id: ?int, dept: ?string, parent: ?int, kids: array<int, int>, size: int, avatar: ?string, initials: string, color: string, open: bool, self: bool}>,
     *     departments: array<int, array{id: int, name: string, ids: array<int, int>, count: int}>
     * }
     *
     * @throws AuthorizationException
     */
    public function forUser(User $user, int $companyId): array
    {
        if (! CurrentCompany::canAccess($companyId, $user)) {
            throw new AuthorizationException('You do not belong to that company.');
        }

        $rows = $this->currentEmployees($companyId);
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->toBase()
            ->get(['id', 'name', 'complete_name', 'parent_id'])
            ->keyBy(fn (object $department): int => (int) $department->id);

        $self = $rows->first(fn (object $row): bool => (int) $row->user_id === (int) $user->getKey());
        $wholeCompany = $this->hierarchy->seesWholeCompany($user) || CurrentCompany::isGroupOwner($user);

        if (! $wholeCompany) {
            $rows = $rows->only(array_keys($this->hierarchyScope($user, $companyId, $rows, $self)));
        }

        $openable = $user->can('view_employee_employee')
            ? array_flip($this->hierarchy->visibleEmployeeIds($user, $companyId)->all())
            : [];

        return $this->tree($companyId, $rows, $departments, $openable, $wholeCompany, $self ? (int) $self->id : null);
    }

    /**
     * Current employees of the company, keyed by id: one query, only the
     * columns the chart shows.
     *
     * @return Collection<int, object>
     */
    protected function currentEmployees(int $companyId): Collection
    {
        $today = Carbon::now((string) config('attendance.monthly_report.timezone', 'Asia/Karachi'))->toDateString();

        return Employee::query()
            ->leftJoin('partners_partners as partners', 'partners.id', '=', 'employees_employees.partner_id')
            ->leftJoin('employees_job_positions as jobs', 'jobs.id', '=', 'employees_employees.job_id')
            ->where('employees_employees.company_id', $companyId)
            ->where('employees_employees.is_active', true)
            ->where(fn ($query) => $query->whereNull('employees_employees.leaving_date')->orWhereDate('employees_employees.leaving_date', '>=', $today))
            ->where(fn ($query) => $query->whereNull('employees_employees.departure_date')->orWhereDate('employees_employees.departure_date', '>=', $today))
            ->orderBy('employees_employees.name')
            ->toBase()
            ->get([
                'employees_employees.id',
                'employees_employees.name',
                'employees_employees.job_title',
                'jobs.name as job_name',
                'employees_employees.department_id',
                'employees_employees.parent_id',
                'employees_employees.user_id',
                'partners.avatar',
            ])
            ->keyBy(fn (object $row): int => (int) $row->id);
    }

    /**
     * What a user without company-wide HR access sees: their HR scope
     * (HrHierarchyService), their chain of managers and their peers.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, true>
     */
    protected function hierarchyScope(User $user, int $companyId, Collection $rows, ?object $self): array
    {
        $visible = array_fill_keys($this->hierarchy->visibleEmployeeIds($user, $companyId)->all(), true);

        if ($self === null) {
            return $visible;
        }

        $visible[(int) $self->id] = true;
        $chain = [];
        $cursor = (int) $self->parent_id;
        while ($cursor > 0 && isset($rows[$cursor]) && ! isset($chain[$cursor])) {
            $chain[$cursor] = true;
            $visible[$cursor] = true;
            $cursor = (int) $rows[$cursor]->parent_id;
        }

        if ((int) $self->parent_id > 0) {
            foreach ($rows as $id => $row) {
                if ((int) $row->parent_id === (int) $self->parent_id) {
                    $visible[$id] = true;
                }
            }
        }

        return $visible;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, object>  $departments
     * @param  array<int, int>  $openable  employee id => index
     * @return array<string, mixed>
     */
    protected function tree(int $companyId, Collection $rows, Collection $departments, array $openable, bool $wholeCompany, ?int $selfId): array
    {
        $children = [];
        $parentOf = [];
        $roots = [];
        foreach ($rows as $id => $row) {
            $parent = (int) $row->parent_id;
            if ($parent > 0 && $parent !== $id && isset($rows[$parent])) {
                $children[$parent][] = $id;
                $parentOf[$id] = $parent;
            } else {
                $roots[] = $id;
            }
        }

        // A reporting loop (bad data) has no root: cut it where it is found so everyone shows once.
        $reached = $this->reach($roots, $children);
        foreach ($rows as $id => $row) {
            if (isset($reached[$id])) {
                continue;
            }

            $parent = $parentOf[$id];
            $children[$parent] = array_values(array_diff($children[$parent], [$id]));
            unset($parentOf[$id]);
            $roots[] = $id;
            $reached += $this->reach([$id], $children);
        }

        $size = $this->subtreeSizes($roots, $children);
        $byManagersThenName = fn (int $a, int $b): int => [($size[$b] ?? 0) > 0, $rows[$a]->name] <=> [($size[$a] ?? 0) > 0, $rows[$b]->name];
        foreach ($children as $parent => $kids) {
            usort($kids, $byManagersThenName);
            $children[$parent] = $kids;
        }
        // The top-level manager (largest tree) first, then everyone without a manager.
        usort($roots, fn (int $a, int $b): int => [$size[$b] ?? 0, $rows[$a]->name] <=> [$size[$a] ?? 0, $rows[$b]->name]);

        $order = [];
        $stack = array_reverse($roots);
        while ($stack !== []) {
            $id = array_pop($stack);
            $order[] = $id;
            foreach (array_reverse($children[$id] ?? []) as $kid) {
                $stack[] = $kid;
            }
        }

        $nodes = [];
        foreach ($order as $id) {
            $row = $rows[$id];
            $nodes[$id] = [
                'id'       => $id,
                'name'     => (string) $row->name,
                'title'    => filled($row->job_title) ? (string) $row->job_title : ($row->job_name !== null ? (string) $row->job_name : null),
                'dept_id'  => $row->department_id !== null ? (int) $row->department_id : null,
                'dept'     => $row->department_id !== null ? ($departments[(int) $row->department_id]->name ?? null) : null,
                'parent'   => $parentOf[$id] ?? null,
                'kids'     => $children[$id] ?? [],
                'size'     => $size[$id] ?? 0,
                'avatar'   => filled($row->avatar) ? Storage::url((string) $row->avatar) : null,
                'initials' => $this->initials((string) $row->name),
                'color'    => self::COLORS[$id % count(self::COLORS)],
                'open'     => isset($openable[$id]),
                'self'     => $id === $selfId,
            ];
        }

        return [
            'company_id'  => $companyId,
            'scope'       => $wholeCompany ? 'company' : 'hierarchy',
            'self_id'     => $selfId !== null && isset($nodes[$selfId]) ? $selfId : null,
            'total'       => count($nodes),
            'roots'       => $roots,
            'order'       => $order,
            'nodes'       => $nodes,
            'departments' => $this->departmentOptions($departments, $nodes),
        ];
    }

    /**
     * Departments that hold someone on the chart (directly or through a
     * sub-department), each with its own id and its sub-departments' ids.
     *
     * @param  Collection<int, object>  $departments
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array{id: int, name: string, ids: array<int, int>, count: int}>
     */
    protected function departmentOptions(Collection $departments, array $nodes): array
    {
        $direct = [];
        foreach ($nodes as $node) {
            if ($node['dept_id'] !== null) {
                $direct[$node['dept_id']] = ($direct[$node['dept_id']] ?? 0) + 1;
            }
        }

        $ids = [];
        $counts = [];
        foreach ($direct as $departmentId => $count) {
            $cursor = $departmentId;
            $seen = [];
            while ($cursor > 0 && isset($departments[$cursor]) && ! isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $ids[$cursor][] = $departmentId;
                $counts[$cursor] = ($counts[$cursor] ?? 0) + $count;
                $cursor = (int) $departments[$cursor]->parent_id;
            }
        }

        return collect($ids)
            ->map(fn (array $memberIds, int $departmentId): array => [
                'id'    => $departmentId,
                'name'  => (string) ($departments[$departmentId]->complete_name ?: $departments[$departmentId]->name),
                'ids'   => array_values(array_unique($memberIds)),
                'count' => $counts[$departmentId],
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $from
     * @param  array<int, array<int, int>>  $children
     * @return array<int, true>
     */
    private function reach(array $from, array $children): array
    {
        $reached = [];
        $stack = $from;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($reached[$id])) {
                continue;
            }

            $reached[$id] = true;
            foreach ($children[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $reached;
    }

    /**
     * Number of people under each employee (all levels).
     *
     * @param  array<int, int>  $roots
     * @param  array<int, array<int, int>>  $children
     * @return array<int, int>
     */
    private function subtreeSizes(array $roots, array $children): array
    {
        $postOrder = [];
        $stack = $roots;
        while ($stack !== []) {
            $id = array_pop($stack);
            $postOrder[] = $id;
            foreach ($children[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        $size = [];
        foreach (array_reverse($postOrder) as $id) {
            $size[$id] = 0;
            foreach ($children[$id] ?? [] as $kid) {
                $size[$id] += 1 + ($size[$kid] ?? 0);
            }
        }

        return $size;
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = collect($words)->filter()->take(2)->map(fn (string $word): string => Str::upper(mb_substr($word, 0, 1)))->implode('');

        return $letters !== '' ? $letters : '?';
    }
}
