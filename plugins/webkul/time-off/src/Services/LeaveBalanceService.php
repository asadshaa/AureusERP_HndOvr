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
 *  - taken     : approved leave (validate_two) of the type starting in the year;
 *  - pending   : leave waiting for a decision (confirm / validate_one);
 *  - remaining : allocated - taken - pending.
 *
 * Refused and cancelled leave never count, so withdrawing or cancelling a
 * leave gives the days back. Days are number_of_days: a half day of a type
 * that requires an allocation is 0.5, a short leave is its hours / 8 (only
 * relevant when the type requires an allocation; the seeded Short leave
 * does not).
 *
 * Only a type that requires an allocation has a balance. The Half day type
 * (half-day request unit, decision of 2026-10-29) does not count as leave:
 * it deducts nothing from any balance and is never refused for balance,
 * whatever its requires_allocation says. It stays a leave type, so an
 * approved Half day still covers half the day in attendance.
 *
 * A request may not take the remaining balance below zero, unless the type
 * allows a negative balance: then down to -max_allowed_negative (no limit
 * when max_allowed_negative is empty).
 */
class LeaveBalanceService
{
    /** @var array<int, string> */
    public const PENDING_STATES = ['confirm', 'validate_one'];

    /**
     * @return array{tracked: bool, year: int, leave_type: string, allocated: float, taken: float, pending: float, remaining: float, allows_negative: bool, max_negative: ?float}
     */
    public function forEmployee(Employee $employee, LeaveType $leaveType, ?int $year = null, ?int $ignoreLeaveId = null): array
    {
        $year ??= self::today()->year;

        if (! self::deductsBalance($leaveType)) {
            return [
                'tracked'         => false,
                'year'            => $year,
                'leave_type'      => (string) $leaveType->name,
                'allocated'       => 0.0,
                'taken'           => 0.0,
                'pending'         => 0.0,
                'remaining'       => 0.0,
                'allows_negative' => false,
                'max_negative'    => null,
            ];
        }

        return $this->usage($employee, $leaveType, $year, $ignoreLeaveId) + [
            'tracked'         => true,
            'year'            => $year,
            'leave_type'      => (string) $leaveType->name,
            'allows_negative' => (bool) $leaveType->allows_negative,
            'max_negative'    => $leaveType->max_allowed_negative !== null ? (float) $leaveType->max_allowed_negative : null,
        ];
    }

    /**
     * Allocated / taken / pending / remaining days of one leave type in a year.
     *
     * @return array{allocated: float, taken: float, pending: float, remaining: float}
     */
    public function usage(Employee $employee, LeaveType $leaveType, int $year, ?int $ignoreLeaveId = null): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $allocated = (float) LeaveAllocation::query()
            ->where('employee_id', $employee->id)
            ->where('holiday_status_id', $leaveType->id)
            ->where('state', State::VALIDATE_TWO->value)
            ->where('date_from', '<=', $yearEnd)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $yearStart))
            ->sum('number_of_days');

        $leaves = Leave::query()
            ->where('employee_id', $employee->id)
            ->where('holiday_status_id', $leaveType->id)
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
     * usage() for several leave types at once, in two queries.
     *
     * @param  iterable<LeaveType>  $leaveTypes
     * @return array<int, array{allocated: float, taken: float, pending: float, remaining: float}> keyed by leave type id
     */
    public function usageForTypes(Employee $employee, iterable $leaveTypes, int $year): array
    {
        $typeIds = collect($leaveTypes)->map(fn (LeaveType $type): int => (int) $type->id)->unique()->values()->all();

        if ($typeIds === []) {
            return [];
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $allocated = LeaveAllocation::query()
            ->where('employee_id', $employee->id)
            ->whereIn('holiday_status_id', $typeIds)
            ->where('state', State::VALIDATE_TWO->value)
            ->where('date_from', '<=', $yearEnd)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $yearStart))
            ->groupBy('holiday_status_id')
            ->selectRaw('holiday_status_id, SUM(number_of_days) AS days')
            ->pluck('days', 'holiday_status_id');

        $leaves = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('holiday_status_id', $typeIds)
            ->whereRaw('COALESCE(request_date_from, date_from) BETWEEN ? AND ?', [$yearStart->toDateTimeString(), $yearEnd->toDateTimeString()])
            ->whereIn('state', [State::VALIDATE_TWO->value, ...self::PENDING_STATES])
            ->groupBy('holiday_status_id', 'state')
            ->selectRaw('holiday_status_id, state, SUM(number_of_days) AS days')
            ->toBase()
            ->get();

        $usage = [];
        foreach ($typeIds as $typeId) {
            $forType = $leaves->where('holiday_status_id', $typeId);
            $allocatedDays = (float) ($allocated[$typeId] ?? 0);
            $taken = (float) $forType->where('state', State::VALIDATE_TWO->value)->sum('days');
            $pending = (float) $forType->whereIn('state', self::PENDING_STATES)->sum('days');

            $usage[$typeId] = [
                'allocated' => round($allocatedDays, 2),
                'taken'     => round($taken, 2),
                'pending'   => round($pending, 2),
                'remaining' => round($allocatedDays - $taken - $pending, 2),
            ];
        }

        return $usage;
    }

    /**
     * usageForTypes() for many employees at once (reports), still in two
     * queries: allocations and leaves are summed per employee and type.
     * Every employee gets every type, zero when there is nothing.
     *
     * @param  iterable<int>  $employeeIds
     * @param  iterable<LeaveType>  $leaveTypes
     * @return array<int, array<int, array{allocated: float, taken: float, pending: float, remaining: float}>> keyed by employee id, then leave type id
     */
    public function usageForEmployees(iterable $employeeIds, iterable $leaveTypes, int $year): array
    {
        $employeeIds = collect($employeeIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $typeIds = collect($leaveTypes)->map(fn (LeaveType $type): int => (int) $type->id)->unique()->values()->all();

        if ($employeeIds === [] || $typeIds === []) {
            return array_fill_keys($employeeIds, []);
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $allocated = [];
        LeaveAllocation::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('holiday_status_id', $typeIds)
            ->where('state', State::VALIDATE_TWO->value)
            ->where('date_from', '<=', $yearEnd)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $yearStart))
            ->groupBy('employee_id', 'holiday_status_id')
            ->selectRaw('employee_id, holiday_status_id, SUM(number_of_days) AS days')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$allocated): void {
                $allocated[(int) $row->employee_id][(int) $row->holiday_status_id] = (float) $row->days;
            });

        $used = [];
        Leave::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('holiday_status_id', $typeIds)
            ->whereRaw('COALESCE(request_date_from, date_from) BETWEEN ? AND ?', [$yearStart->toDateTimeString(), $yearEnd->toDateTimeString()])
            ->whereIn('state', [State::VALIDATE_TWO->value, ...self::PENDING_STATES])
            ->groupBy('employee_id', 'holiday_status_id', 'state')
            ->selectRaw('employee_id, holiday_status_id, state, SUM(number_of_days) AS days')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$used): void {
                $bucket = $row->state === State::VALIDATE_TWO->value ? 'taken' : 'pending';
                $used[(int) $row->employee_id][(int) $row->holiday_status_id][$bucket] = ($used[(int) $row->employee_id][(int) $row->holiday_status_id][$bucket] ?? 0.0) + (float) $row->days;
            });

        $usage = [];
        foreach ($employeeIds as $employeeId) {
            foreach ($typeIds as $typeId) {
                $allocatedDays = $allocated[$employeeId][$typeId] ?? 0.0;
                $taken = $used[$employeeId][$typeId]['taken'] ?? 0.0;
                $pending = $used[$employeeId][$typeId]['pending'] ?? 0.0;

                $usage[$employeeId][$typeId] = [
                    'allocated' => round($allocatedDays, 2),
                    'taken'     => round($taken, 2),
                    'pending'   => round($pending, 2),
                    'remaining' => round($allocatedDays - $taken - $pending, 2),
                ];
            }
        }

        return $usage;
    }

    /**
     * Why the employee may not request $days of $leaveType starting on $date,
     * or null when the request fits the balance (always null for a type
     * without a balance, such as Half day).
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

        $name = (string) $leaveType->name;

        if ($balance['allocated'] <= 0 && ! $balance['allows_negative']) {
            return [
                'reason'  => 'no_allocation',
                'message' => "There is no approved {$name} allocation for {$year}. Ask HR to allocate {$name} first.",
                'balance' => $balance,
            ];
        }

        if ($floor - 0.0001 > $balance['remaining'] - $days) {
            return [
                'reason'  => 'insufficient',
                'message' => sprintf(
                    'Not enough %s balance for %d: %s day(s) remaining (allocated %s, taken %s, pending %s), %s requested.',
                    $name,
                    $year,
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
        if (self::isHalfDayType($leaveType)) {
            return "{$leaveType->name} does not count as leave: no balance is deducted.";
        }

        $balance = $this->forEmployee($employee, $leaveType, $year, $ignoreLeaveId);

        if (! $balance['tracked']) {
            return "{$leaveType->name} needs no allocation: no balance is deducted.";
        }

        $negative = $balance['allows_negative']
            ? ($balance['max_negative'] === null ? ' (may go negative)' : ' (may go down to -'.self::format($balance['max_negative']).')')
            : '';

        return sprintf(
            '%s %d: available %s, taken %s, pending %s, remaining %s day(s)%s.',
            $leaveType->name,
            $balance['year'],
            self::format($balance['allocated']),
            self::format($balance['taken']),
            self::format($balance['pending']),
            self::format($balance['remaining']),
            $negative,
        );
    }

    /** Whether a leave of this type is deducted from (and checked against) a balance. */
    public static function deductsBalance(LeaveType $leaveType): bool
    {
        return self::requiresAllocation($leaveType) && ! self::isHalfDayType($leaveType);
    }

    /** The Half day type (half-day request unit): it never counts as leave in a balance. */
    public static function isHalfDayType(LeaveType $leaveType): bool
    {
        return self::requestUnit($leaveType) === 'half_day';
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
