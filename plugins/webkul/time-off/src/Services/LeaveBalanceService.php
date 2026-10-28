<?php

namespace Webkul\TimeOff\Services;

use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

/**
 * Leave balance of one employee for one leave type in one allocation year
 * (calendar year, Asia/Karachi), from the existing data only:
 *
 *  - allocated : approved allocations (state validate_two) of the type that
 *                overlap the year (the yearly grant plus any carry-forward);
 *  - taken     : approved leave (validate_two) starting in the year;
 *  - pending   : leave waiting for a decision (confirm / validate_one);
 *  - remaining : allocated - taken - pending.
 *
 * Refused and cancelled leave never count, so withdrawing or cancelling a
 * leave gives the days back. Days are number_of_days: a half day is 0.5, a
 * short leave is its hours / 8 (only relevant when the type requires an
 * allocation; the seeded Short leave does not).
 *
 * Half day (decision 2026-10-28): a half-day type that needs no allocation
 * of its own ("Half day") uses the employee's Casual Leave balance, or
 * Annual Leave when the company has no Casual Leave type. Its 0.5 days are
 * shown and checked against that pool, and counted as taken there.
 *
 * A request may not take the remaining balance below zero, unless the pool
 * type allows a negative balance: then down to -max_allowed_negative (no
 * limit when max_allowed_negative is empty).
 */
class LeaveBalanceService
{
    /** Pools a half-day type without its own allocation uses, in order. */
    public const HALF_DAY_POOLS = ['Casual Leave', 'Annual Leave'];

    /** @var array<int, string> */
    public const PENDING_STATES = ['confirm', 'validate_one'];

    /**
     * @return array{tracked: bool, year: int, leave_type: string, pool_type_id: ?int, pool_type: ?string, shared_pool: bool, allocated: float, taken: float, pending: float, remaining: float, allows_negative: bool, max_negative: ?float}
     */
    public function forEmployee(Employee $employee, LeaveType $leaveType, ?int $year = null, ?int $ignoreLeaveId = null): array
    {
        $year ??= self::today()->year;
        $pool = $this->poolTypeFor($leaveType, (int) $employee->company_id);

        if (! $pool) {
            return [
                'tracked'         => false,
                'year'            => $year,
                'leave_type'      => (string) $leaveType->name,
                'pool_type_id'    => null,
                'pool_type'       => null,
                'shared_pool'     => false,
                'allocated'       => 0.0,
                'taken'           => 0.0,
                'pending'         => 0.0,
                'remaining'       => 0.0,
                'allows_negative' => false,
                'max_negative'    => null,
            ];
        }

        $usage = $this->poolUsage($employee, $pool, $year, $ignoreLeaveId);

        return $usage + [
            'tracked'         => true,
            'year'            => $year,
            'leave_type'      => (string) $leaveType->name,
            'pool_type_id'    => (int) $pool->id,
            'pool_type'       => (string) $pool->name,
            'shared_pool'     => (int) $pool->id !== (int) $leaveType->id,
            'allows_negative' => (bool) $pool->allows_negative,
            'max_negative'    => $pool->max_allowed_negative !== null ? (float) $pool->max_allowed_negative : null,
        ];
    }

    /**
     * Allocated / taken / pending / remaining days of a pool type, including
     * the half-day types that draw from it.
     *
     * @return array{allocated: float, taken: float, pending: float, remaining: float}
     */
    public function poolUsage(Employee $employee, LeaveType $pool, int $year, ?int $ignoreLeaveId = null): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $typeIds = $this->consumingTypeIds($pool, (int) $employee->company_id);

        $allocated = (float) LeaveAllocation::query()
            ->where('employee_id', $employee->id)
            ->where('holiday_status_id', $pool->id)
            ->where('state', State::VALIDATE_TWO->value)
            ->where('date_from', '<=', $yearEnd)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $yearStart))
            ->sum('number_of_days');

        $leaves = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('holiday_status_id', $typeIds)
            ->whereRaw('COALESCE(request_date_from, date_from) BETWEEN ? AND ?', [$yearStart->toDateTimeString(), $yearEnd->toDateTimeString()])
            ->when($ignoreLeaveId, fn ($query) => $query->whereKeyNot($ignoreLeaveId));

        $taken = (float) (clone $leaves)->where('state', State::VALIDATE_TWO->value)->sum('number_of_days');
        $pending = (float) (clone $leaves)->whereIn('state', self::PENDING_STATES)->sum('number_of_days');

        return [
            'allocated' => round($allocated, 2),
            'taken'     => round($taken, 2),
            'pending'   => round($pending, 2),
            'remaining' => round($allocated - $taken - $pending, 2),
        ];
    }

    /**
     * Why the employee may not request $days of $leaveType starting on $date,
     * or null when the request fits the balance.
     *
     * @return array{reason: string, message: string, balance: ?array<string, mixed>}|null
     */
    public function refusal(Employee $employee, LeaveType $leaveType, float $days, ?string $date = null, ?int $ignoreLeaveId = null): ?array
    {
        if ($leaveType->company_id !== null && (int) $leaveType->company_id !== (int) $employee->company_id) {
            return [
                'reason'  => 'company',
                'message' => "{$leaveType->name} belongs to another company.",
                'balance' => null,
            ];
        }

        $year = $date ? Carbon::parse($date)->year : self::today()->year;
        $balance = $this->forEmployee($employee, $leaveType, $year, $ignoreLeaveId);

        if (! $balance['tracked'] || $days <= 0) {
            return null;
        }

        $floor = 0.0;
        if ($balance['allows_negative']) {
            if ($balance['max_negative'] === null) {
                return null;
            }

            $floor = -abs($balance['max_negative']);
        }

        $pool = $balance['pool_type'];
        $via = $balance['shared_pool'] ? " ({$leaveType->name} uses the {$pool} balance)" : '';

        if ($balance['allocated'] <= 0 && ! $balance['allows_negative']) {
            return [
                'reason'  => 'no_allocation',
                'message' => "There is no approved {$pool} allocation for {$year}{$via}. Ask HR to allocate {$pool} first.",
                'balance' => $balance,
            ];
        }

        if ($floor - 0.0001 > $balance['remaining'] - $days) {
            return [
                'reason'  => 'insufficient',
                'message' => sprintf(
                    'Not enough %s balance for %d%s: %s day(s) remaining (allocated %s, taken %s, pending %s), %s requested.',
                    $pool,
                    $year,
                    $via,
                    self::format($balance['remaining']),
                    self::format($balance['allocated']),
                    self::format($balance['taken']),
                    self::format($balance['pending']),
                    self::format($days),
                ),
                'balance' => $balance,
            ];
        }

        return null;
    }

    /**
     * Server-side check of a leave about to be saved (Leave model, saving).
     *
     * @throws ValidationException
     */
    public function assertLeaveFits(Leave $leave): void
    {
        $employee = Employee::query()->find($leave->employee_id);
        $leaveType = LeaveType::query()->find($leave->holiday_status_id);

        if (! $employee || ! $leaveType) {
            return;
        }

        $date = $leave->request_date_from ?? $leave->date_from;
        $refusal = $this->refusal(
            $employee,
            $leaveType,
            (float) $leave->number_of_days,
            $date ? Carbon::parse($date)->toDateString() : null,
            $leave->exists ? (int) $leave->id : null,
        );

        if ($refusal) {
            throw ValidationException::withMessages(['holiday_status_id' => $refusal['message']]);
        }
    }

    /** One-line summary for the request form. */
    public function summary(Employee $employee, LeaveType $leaveType, ?int $year = null, ?int $ignoreLeaveId = null): string
    {
        $balance = $this->forEmployee($employee, $leaveType, $year, $ignoreLeaveId);

        if (! $balance['tracked']) {
            return "{$leaveType->name} needs no allocation: no balance is deducted.";
        }

        $prefix = $balance['shared_pool']
            ? "Uses your {$balance['pool_type']} balance. "
            : '';

        $negative = $balance['allows_negative']
            ? ($balance['max_negative'] === null ? ' (may go negative)' : ' (may go down to -'.self::format($balance['max_negative']).')')
            : '';

        return sprintf(
            '%s%s %d: available %s, taken %s, pending %s, remaining %s day(s)%s.',
            $prefix,
            $balance['pool_type'],
            $balance['year'],
            self::format($balance['allocated']),
            self::format($balance['taken']),
            self::format($balance['pending']),
            self::format($balance['remaining']),
            $negative,
        );
    }

    /**
     * The type whose allocation a leave of $leaveType uses: itself when it
     * requires an allocation, the company's Casual (else Annual) Leave for a
     * half-day type without its own allocation, null when nothing is deducted.
     */
    public function poolTypeFor(LeaveType $leaveType, int $companyId): ?LeaveType
    {
        if (self::requiresAllocation($leaveType)) {
            return $leaveType;
        }

        if (self::requestUnit($leaveType) !== 'half_day') {
            return null;
        }

        foreach (self::HALF_DAY_POOLS as $name) {
            $pool = LeaveType::query()
                ->where('name', $name)
                ->where('is_active', true)
                ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->orderByRaw('company_id IS NULL')
                ->orderBy('id')
                ->first();

            if ($pool && self::requiresAllocation($pool)) {
                return $pool;
            }
        }

        return null;
    }

    /** @return array<int, int> the pool type and every half-day type of the company drawing from it */
    public function consumingTypeIds(LeaveType $pool, int $companyId): array
    {
        $ids = [(int) $pool->id];

        $halfDayTypes = LeaveType::query()
            ->where('request_unit', 'half_day')
            ->where('requires_allocation', 'no')
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->get();

        foreach ($halfDayTypes as $halfDayType) {
            if ((int) $this->poolTypeFor($halfDayType, $companyId)?->id === (int) $pool->id) {
                $ids[] = (int) $halfDayType->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function requiresAllocation(LeaveType $leaveType): bool
    {
        $value = $leaveType->requires_allocation instanceof BackedEnum ? $leaveType->requires_allocation->value : $leaveType->requires_allocation;

        return $value === 'yes' || $value === true || $value === 1 || $value === '1';
    }

    public static function requestUnit(LeaveType $leaveType): ?string
    {
        $value = $leaveType->request_unit;

        return $value instanceof BackedEnum ? (string) $value->value : ($value !== null ? (string) $value : null);
    }

    public static function today(): Carbon
    {
        return Carbon::now((string) config('attendance.monthly_report.timezone', 'Asia/Karachi'));
    }

    public static function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }
}
