<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveBalanceService;

/**
 * Employees -> Reportings -> Leave balances (HR Parity 3.7): Annual, Sick and
 * Casual Leave of every employee for one allocation year: allocated, taken,
 * pending and remaining, exactly as LeaveBalanceService counts them
 * (approved allocations; approved leave taken; confirm / first approval
 * pending; refused and cancelled never count; the Half day type is not
 * leave). Each column is the company's own type of that name, else the
 * shared one, like the employee dashboard cards.
 *
 * Queries do not grow with the number of employees: one for the people,
 * one for the leave types, two for all allocations and leave of the year.
 */
class LeaveBalanceReport
{
    /** @var array<string, array{name: string, keywords: array<int, string>}> */
    public const TYPES = [
        'annual' => ['name' => 'Annual Leave', 'keywords' => ['annual', 'vacation']],
        'sick'   => ['name' => 'Sick Leave', 'keywords' => ['sick', 'medical']],
        'casual' => ['name' => 'Casual Leave', 'keywords' => ['casual']],
    ];

    public function __construct(
        protected HrReportScope $scope,
        protected EmployeeMovements $movements,
        protected LeaveBalanceService $balances,
    ) {}

    /**
     * @return array{
     *     company: array{id: int, name: string}, department: ?string, generated_at: CarbonImmutable, generated_by: string,
     *     year: int,
     *     types: array<int, array{key: string, id: ?int, name: string, tracked: bool}>,
     *     rows: array<int, array{id: int, code: ?string, name: string, department: string, job_title: ?string, left: ?string, balances: array<string, ?array{allocated: ?float, taken: float, pending: float, remaining: ?float}>}>,
     *     totals: array<string, ?array{allocated: ?float, taken: float, pending: float, remaining: ?float}>,
     *     without_allocation: int
     * }
     *
     * @throws AuthorizationException
     */
    public function build(User $user, int $companyId, int $year, ?int $departmentId = null): array
    {
        $people = $this->movements->people($user, $companyId, $departmentId)
            ->filter(fn (array $person): bool => $person['joined'] <= $year.'-12-31' && ($person['left'] === null || $person['left'] >= $year.'-01-01'))
            ->values();

        $types = $this->leaveTypes($companyId);
        $usage = $this->balances->usageForEmployees($people->pluck('id'), $types->filter()->values(), $year);

        $columns = [];
        foreach (self::TYPES as $key => $spec) {
            $type = $types[$key];
            $columns[] = [
                'key'     => $key,
                'id'      => $type?->id !== null ? (int) $type->id : null,
                'name'    => $type ? (string) $type->name : $spec['name'],
                'tracked' => $type !== null && LeaveBalanceService::deductsBalance($type),
            ];
        }

        $rows = [];
        $totals = array_fill_keys(array_keys(self::TYPES), null);
        $withoutAllocation = 0;
        foreach ($people as $person) {
            $balances = [];
            $hasAllocation = false;
            foreach ($columns as $column) {
                if ($column['id'] === null) {
                    $balances[$column['key']] = null;

                    continue;
                }

                $figures = $usage[$person['id']][$column['id']];
                $balances[$column['key']] = [
                    'allocated' => $column['tracked'] ? $figures['allocated'] : null,
                    'taken'     => $figures['taken'],
                    'pending'   => $figures['pending'],
                    'remaining' => $column['tracked'] ? $figures['remaining'] : null,
                ];
                $hasAllocation = $hasAllocation || ($column['tracked'] && $figures['allocated'] > 0);

                $total = $totals[$column['key']] ?? ['allocated' => $column['tracked'] ? 0.0 : null, 'taken' => 0.0, 'pending' => 0.0, 'remaining' => $column['tracked'] ? 0.0 : null];
                foreach (['allocated', 'taken', 'pending', 'remaining'] as $figure) {
                    if ($total[$figure] !== null) {
                        $total[$figure] = round($total[$figure] + $balances[$column['key']][$figure], 2);
                    }
                }
                $totals[$column['key']] = $total;
            }

            $withoutAllocation += (int) ! $hasAllocation;
            $rows[] = [
                'id'         => $person['id'],
                'code'       => $person['code'],
                'name'       => $person['name'],
                'department' => $person['department'],
                'job_title'  => $person['job_title'],
                'left'       => $person['left'],
                'balances'   => $balances,
            ];
        }

        return $this->scope->header($user, $companyId, $departmentId) + [
            'year'               => $year,
            'types'              => $columns,
            'rows'               => $rows,
            'totals'             => $totals,
            'without_allocation' => $withoutAllocation,
        ];
    }

    /**
     * Annual, Sick and Casual Leave of the company: its own active type of
     * that name, else a shared one, else one whose name has the keyword and
     * that counts against a balance. One query.
     *
     * @return Collection<string, ?LeaveType>
     */
    public function leaveTypes(int $companyId): Collection
    {
        $candidates = LeaveType::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderByRaw('company_id IS NULL')
            ->orderBy('id')
            ->get();

        return collect(self::TYPES)->map(fn (array $spec): ?LeaveType => $candidates->first(fn (LeaveType $type): bool => strcasecmp(trim((string) $type->name), $spec['name']) === 0)
            ?? $candidates->first(fn (LeaveType $type): bool => LeaveBalanceService::deductsBalance($type)
                && Str::contains(strtolower((string) $type->name), $spec['keywords'])));
    }
}
