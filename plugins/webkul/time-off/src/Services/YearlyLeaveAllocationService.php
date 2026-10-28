<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\AllocationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;

/**
 * Yearly leave allocation (time-off:yearly-allocation, 1 January 00:30
 * Asia/Karachi). For every active policy of a company
 * (LeaveAllocationPolicy) and every active employee of that company it
 * creates one approved allocation for the year, 1 Jan - 31 Dec:
 *
 *  - grant      : the policy's annual days; an employee who joins during the
 *                 year gets annual days x months left / 12 (the joining month
 *                 counts in full), rounded to the nearest half day;
 *  - carried    : the previous year's remaining balance (allocated - taken -
 *                 pending, LeaveBalanceService; Half day counted on Casual),
 *                 never below 0 and at most the policy's carry-forward cap.
 *                 Stored on the allocation as expiring_carryover_days and in
 *                 the notes, so the split stays auditable.
 *
 * Idempotent: an allocation of the type starting on 1 January of the year
 * (the same key the hire-time allocation uses) means "already allocated"
 * and nothing is created or changed. A dry run computes the same rows and
 * writes nothing.
 */
class YearlyLeaveAllocationService
{
    public function __construct(protected LeaveBalanceService $balances) {}

    /**
     * @return array{year: int, dry_run: bool, created: int, existing: int, skipped: int, days: float, rows: array<int, array{company: string, employee: string, leave_type: string, grant: float, carried: float, total: float, status: string}>}
     */
    public function run(int $year, ?int $companyId = null, bool $dryRun = false): array
    {
        $result = ['year' => $year, 'dry_run' => $dryRun, 'created' => 0, 'existing' => 0, 'skipped' => 0, 'days' => 0.0, 'rows' => []];

        $companies = Company::query()
            ->when($companyId, fn ($query) => $query->whereKey($companyId))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $policies = LeaveAllocationPolicy::forCompany((int) $company->id, ! $dryRun)
                ->filter(fn (LeaveAllocationPolicy $policy): bool => $policy->is_active
                    && $policy->leaveType
                    && $policy->leaveType->is_active
                    && ((float) $policy->annual_days > 0 || (float) $policy->carry_forward_cap > 0))
                ->values();

            if ($policies->isEmpty()) {
                continue;
            }

            foreach ($this->employeesFor((int) $company->id, $year) as $employee) {
                foreach ($policies as $policy) {
                    $row = $this->allocate($company, $employee, $policy, $year, $dryRun);
                    $result['rows'][] = $row;
                    $result[$row['status'] === 'created' || $row['status'] === 'would create' ? 'created' : ($row['status'] === 'exists' ? 'existing' : 'skipped')]++;
                    if ($row['status'] === 'created' || $row['status'] === 'would create') {
                        $result['days'] += $row['total'];
                    }
                }
            }
        }

        return $result;
    }

    /** Annual days x months left in the year / 12 for a joiner, nearest half day. */
    public static function prorate(float $annualDays, ?Carbon $joiningDate, int $year): float
    {
        if (! $joiningDate || $joiningDate->year < $year) {
            return $annualDays;
        }

        if ($joiningDate->year > $year) {
            return 0.0;
        }

        $monthsLeft = 12 - $joiningDate->month + 1;

        return round($annualDays * $monthsLeft / 12 * 2) / 2;
    }

    /** @return iterable<int, Employee> */
    private function employeesFor(int $companyId, int $year): iterable
    {
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $yearStart))
            ->where(fn ($query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $yearEnd))
            ->orderBy('id')
            ->get();
    }

    /** @return array{company: string, employee: string, leave_type: string, grant: float, carried: float, total: float, status: string} */
    private function allocate(Company $company, Employee $employee, LeaveAllocationPolicy $policy, int $year, bool $dryRun): array
    {
        $leaveType = $policy->leaveType;
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $row = [
            'company'    => (string) $company->name,
            'employee'   => (string) $employee->name,
            'leave_type' => (string) $leaveType->name,
            'grant'      => 0.0,
            'carried'    => 0.0,
            'total'      => 0.0,
            'status'     => 'exists',
        ];

        $existing = fn (): bool => LeaveAllocation::query()
            ->where('employee_id', $employee->id)
            ->where('holiday_status_id', $leaveType->id)
            ->where('date_from', $yearStart->toDateTimeString())
            ->exists();

        if ($existing()) {
            return $row;
        }

        $grant = self::prorate((float) $policy->annual_days, $employee->joining_date ? Carbon::parse($employee->joining_date) : null, $year);
        $carried = $this->carryForward($employee, $policy, $year);
        $total = round($grant + $carried, 2);

        $row['grant'] = $grant;
        $row['carried'] = $carried;
        $row['total'] = $total;

        if ($total <= 0) {
            $row['status'] = 'nothing to grant';

            return $row;
        }

        if ($dryRun) {
            $row['status'] = 'would create';

            return $row;
        }

        $created = DB::transaction(function () use ($employee, $leaveType, $yearStart, $yearEnd, $year, $grant, $carried, $total, $policy, $existing): bool {
            // Re-checked under a lock on the employee so two overlapping runs cannot both create it.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if ($existing()) {
                return false;
            }

            $notes = sprintf(
                'Yearly allocation %d: %s day(s) granted%s.',
                $year,
                LeaveBalanceService::format($grant),
                $carried > 0
                    ? sprintf(' + %s carried forward from %d (cap %s)', LeaveBalanceService::format($carried), $year - 1, LeaveBalanceService::format((float) $policy->carry_forward_cap))
                    : '',
            );

            LeaveAllocation::query()->create([
                'holiday_status_id'       => $leaveType->id,
                'employee_id'             => $employee->id,
                'employee_company_id'     => $employee->company_id,
                'department_id'           => $employee->department_id,
                'name'                    => "{$leaveType->name} {$year}",
                'state'                   => State::VALIDATE_TWO->value,
                'allocation_type'         => AllocationType::REGULAR,
                'date_from'               => $yearStart,
                'date_to'                 => $yearEnd,
                'number_of_days'          => $total,
                'expiring_carryover_days' => $carried,
                'notes'                   => $notes,
            ]);

            return true;
        });

        $row['status'] = $created ? 'created' : 'exists';

        return $row;
    }

    private function carryForward(Employee $employee, LeaveAllocationPolicy $policy, int $year): float
    {
        $cap = (float) $policy->carry_forward_cap;
        if ($cap <= 0) {
            return 0.0;
        }

        // Someone joining this year (or later) has no previous year to carry from, even if an
        // allocation was made when their record was created ahead of the joining date.
        if ($employee->joining_date && Carbon::parse($employee->joining_date)->year >= $year) {
            return 0.0;
        }

        $previous = $this->balances->poolUsage($employee, $policy->leaveType, $year - 1);
        if ($previous['allocated'] <= 0) {
            return 0.0;
        }

        return round(min($cap, max(0.0, $previous['remaining'])), 2);
    }
}
