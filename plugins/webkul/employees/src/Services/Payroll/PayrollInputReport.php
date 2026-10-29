<?php

namespace Webkul\Employee\Services\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\ApprovedLeaveLookup;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Monthly payroll-input export (HR parity plan 1.5): one row per employee
 * employed in the company during the month (joiners and leavers included),
 * with what the payroll person needs to run payroll elsewhere. Aureus does
 * not calculate pay, tax, EOBI or payslips.
 *
 * Attendance comes from the monthly attendance report: the frozen snapshot
 * when the month is finalised (AttendanceMonthLockService), calculated live
 * otherwise (the export is then a DRAFT). Paid / unpaid leave is split by the
 * Time Off leave type's "unpaid" flag (leave of a finalised month is locked,
 * so this matches the snapshot). Loan instalments and advance deductions
 * come from RequestInstalmentService (due or already deducted in the month).
 *
 * CNIC, bank account and base salary are only included for users with
 * hr_view_sensitive_employee_data. Access needs hr_export_payroll and
 * membership of the company; rows are limited to the user's HR scope.
 */
class PayrollInputReport
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected MonthlyAttendanceReport $attendance,
        protected AttendanceMonthLockService $locks,
        protected RequestInstalmentService $instalments,
    ) {}

    public static function canExport(?User $user): bool
    {
        return $user !== null && $user->can(HrPermissions::ExportPayroll);
    }

    public static function canSeeSensitive(?User $user): bool
    {
        return $user !== null && $user->can(HrPermissions::ViewSensitiveEmployeeData);
    }

    /**
     * The export's columns in order. type: text | date | int | days | hours | minutes | money.
     *
     * @return array<string, array{label: string, type: string, sensitive: bool, total: bool}>
     */
    public function columns(bool $withSensitive): array
    {
        $columns = [
            'code'              => ['Employee code', 'text', false, false],
            'name'              => ['Name', 'text', false, false],
            'cnic'              => ['CNIC', 'text', true, false],
            'bank_account'      => ['Bank account', 'text', true, false],
            'department'        => ['Department', 'text', false, false],
            'designation'       => ['Designation', 'text', false, false],
            'joining_date'      => ['Joining date', 'date', false, false],
            'leaving_date'      => ['Leaving date', 'date', false, false],
            'base_salary'       => ['Base salary', 'money', true, true],
            'working_days'      => ['Working days in month', 'int', false, true],
            'present_days'      => ['Days present', 'int', false, true],
            'absent_days'       => ['Absent days (unpaid)', 'int', false, true],
            'paid_leave_days'   => ['Paid leave days', 'days', false, true],
            'unpaid_leave_days' => ['Unpaid leave days', 'days', false, true],
            'half_days'         => ['Half days (in leave days)', 'int', false, true],
            'late_count'        => ['Late count', 'int', false, true],
            'late_minutes'      => ['Late minutes after grace', 'minutes', false, true],
            'grace_balance'     => ['Grace balance (mins)', 'minutes', false, false],
            'ot_weekday_hours'  => ['Approved OT hours (weekday)', 'hours', false, true],
            'ot_weekend_hours'  => ['Approved OT hours (weekend / holiday)', 'hours', false, true],
            'loan_due'          => ['Loan instalment due', 'money', false, true],
            'advance_due'       => ['Advance deduction due', 'money', false, true],
            'notes'             => ['Notes', 'text', false, false],
        ];

        return collect($columns)
            ->reject(fn (array $column): bool => $column[2] && ! $withSensitive)
            ->map(fn (array $column): array => ['label' => $column[0], 'type' => $column[1], 'sensitive' => $column[2], 'total' => $column[3]])
            ->all();
    }

    /**
     * @param  string  $month  Y-m
     * @return array{company: array{id: int, name: string}, month: string, month_label: string, generated_at: CarbonImmutable, generated_by: string, finalised: ?array{at: CarbonImmutable, by: ?string}, draft: bool, in_progress: bool, sensitive: bool, columns: array<string, array<string, mixed>>, rows: array<int, array<string, mixed>>, totals: array<string, float|int>, deductions: array{due_count: int, due_amount: float}, employee_ids: array<int, int>}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function build(User $user, int $companyId, string $month): array
    {
        if (! static::canExport($user)) {
            throw new AuthorizationException('You may not export the payroll input.');
        }
        if (! CurrentCompany::canAccess($companyId, $user)) {
            throw new AuthorizationException('You do not belong to that company.');
        }
        if (! RequestInstalmentService::isMonth($month)) {
            throw new InvalidArgumentException('The month must look like 2026-10.');
        }

        $timezone = $this->attendance->timezone();
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $lock = $this->locks->finalisedLock($companyId, $month);
        $sensitive = static::canSeeSensitive($user);

        $employees = $this->employees($user, $companyId, $monthStart);
        $ids = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $snapshots = $lock !== null ? $this->locks->snapshotsFor($companyId, $month, $ids) : [];
        $liveIds = array_values(array_diff($ids, array_keys($snapshots)));
        $live = $liveIds !== [] ? $this->attendance->forEmployeesOfCompany($companyId, $month, $liveIds) : [];

        [$leaves, $unpaidTypeIds] = $this->leaveContext($ids, $monthStart->toDateString(), $monthEnd->toDateString());
        $deductions = $this->instalments->monthFor($companyId, $month, $ids);
        $grace = (int) config('attendance.monthly_report.late_grace_minutes', 0);

        $context = [
            'month_start' => $monthStart,
            'month_end'   => $monthEnd,
            'finalised'   => $lock !== null,
            'in_progress' => $monthEnd->gte($today),
            'today'       => $today,
            'sensitive'   => $sensitive,
            'grace'       => $grace,
        ];

        $rows = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $report = $snapshots[$id] ?? $live[$id] ?? null;

            $rows[] = $this->row($employee, $report, $context + [
                'from_snapshot' => array_key_exists($id, $snapshots),
                'leaves'        => $leaves[$id] ?? [],
                'unpaid_types'  => $unpaidTypeIds,
                'deductions'    => $deductions[$id] ?? null,
            ]);
        }

        $columns = $this->columns($sensitive);
        $rows = array_map(fn (array $row): array => array_intersect_key($row, $columns), $rows);

        $totals = [];
        foreach ($columns as $key => $column) {
            if ($column['total']) {
                $totals[$key] = round((float) collect($rows)->sum(fn (array $row): float => (float) ($row[$key] ?? 0)), 2);
            }
        }

        return [
            'company'      => ['id' => $companyId, 'name' => (string) Company::query()->whereKey($companyId)->value('name')],
            'month'        => $month,
            'month_label'  => $monthStart->format('F Y'),
            'generated_at' => CarbonImmutable::now($timezone),
            'generated_by' => (string) $user->name,
            'finalised'    => $lock !== null ? [
                'at' => CarbonImmutable::parse($lock->finalised_at)->setTimezone($timezone),
                'by' => $lock->finaliser?->name,
            ] : null,
            'draft'        => $lock === null,
            'in_progress'  => $context['in_progress'],
            'sensitive'    => $sensitive,
            'columns'      => $columns,
            'rows'         => $rows,
            'totals'       => $totals,
            'deductions'   => [
                'due_count'  => (int) collect($deductions)->sum('due_count'),
                'due_amount' => round((float) collect($deductions)->sum(fn (array $line): float => (float) $line['due_amount']), 2),
            ],
            'employee_ids' => $ids,
        ];
    }

    /**
     * Employed in the company during the month (the monthly attendance
     * report's rule: not departed before it, not joined after it, active or
     * with a departure date), within the user's HR scope.
     *
     * @return Collection<int, Employee>
     */
    protected function employees(User $user, int $companyId, CarbonImmutable $monthStart): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $this->hierarchy->visibleEmployeeIds($user, $companyId))
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $monthStart->toDateString()))
            ->where(fn ($query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $monthStart->endOfMonth()->toDateString()))
            ->where(fn ($query) => $query->where('is_active', true)->orWhereNotNull('departure_date'))
            ->with(['department', 'job', 'bankAccount'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>|null  $report  the employee's monthly attendance (snapshot or live)
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function row(Employee $employee, ?array $report, array $context): array
    {
        $summary = (array) ($report['summary'] ?? []);
        $days = collect($report['rows'] ?? []);
        [$paidLeave, $unpaidLeave, $halfDays] = $this->leaveSplit($days, $context['leaves'], $context['unpaid_types'], (float) ($summary['leave_days'] ?? 0));

        $joined = $employee->joining_date ? CarbonImmutable::parse($employee->joining_date) : null;
        $leftValue = $employee->departure_date ?: $employee->leaving_date;
        $left = $leftValue ? CarbonImmutable::parse($leftValue) : null;
        $offDay = fn (array $day): bool => in_array($day['type'] ?? null, ['weekend', 'holiday'], true);
        $deductions = $context['deductions'];

        $notes = [];
        if (! $context['finalised']) {
            $notes[] = 'Month not finalised';
        } elseif (! $context['from_snapshot']) {
            $notes[] = 'Not in the finalised snapshot (calculated live)';
        }
        if ($context['in_progress']) {
            $notes[] = 'Month in progress (counted to '.$context['today']->format('d M Y').')';
        }
        if ($joined !== null && $joined->gte($context['month_start']) && $joined->lte($context['month_end'])) {
            $notes[] = 'Joined mid-month ('.$joined->format('d M Y').')';
        }
        if ($left !== null && $left->lte($context['month_end'])) {
            $notes[] = 'Left on '.$left->format('d M Y');
        }
        if ((int) ($summary['missing_check_outs'] ?? 0) > 0) {
            $notes[] = (int) $summary['missing_check_outs'].' missing check-out(s)';
        }
        if ((int) ($summary['overtime_pending_minutes'] ?? 0) > 0) {
            $notes[] = 'OT pending approval: '.round((int) $summary['overtime_pending_minutes'] / 60, 2).' h (not included)';
        }
        if ($context['sensitive'] && $employee->base_salary === null) {
            $notes[] = 'No base salary on file';
        }
        if ($report === null) {
            $notes[] = 'No attendance calculated';
        }
        foreach ((array) ($deductions['notes'] ?? []) as $note) {
            $notes[] = $note;
        }

        return [
            'employee_id'       => (int) $employee->id,
            'code'              => (string) ($employee->employee_number ?: $employee->barcode ?: ''),
            'name'              => (string) $employee->name,
            'cnic'              => (string) ($employee->cnic ?? ''),
            'bank_account'      => (string) ($employee->bankAccount?->account_number ?? ''),
            'department'        => (string) ($employee->department?->name ?? ''),
            'designation'       => (string) ($employee->job_title ?: $employee->job?->name ?: ''),
            'joining_date'      => $joined?->toDateString(),
            'leaving_date'      => $left?->toDateString(),
            'base_salary'       => $employee->base_salary !== null ? round((float) $employee->base_salary, 2) : null,
            'working_days'      => $days->filter(fn (array $day): bool => in_array($day['type'] ?? null, ['working', 'leave'], true))->count(),
            'present_days'      => (int) ($summary['present_days'] ?? 0),
            'absent_days'       => (int) ($summary['absent_days'] ?? 0),
            'paid_leave_days'   => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'half_days'         => $halfDays,
            'late_count'        => (int) ($summary['late_days'] ?? 0),
            'late_minutes'      => (int) $days
                ->filter(fn (array $day): bool => ($day['working'] ?? false) && ($day['late'] ?? false))
                ->sum(fn (array $day): int => max(0, (int) ($day['late_minutes'] ?? 0) - $context['grace'])),
            'grace_balance'     => (int) ($summary['grace_balance_minutes'] ?? 0),
            'ot_weekday_hours'  => round($days->reject($offDay)->sum(fn (array $day): int => (int) ($day['overtime_minutes'] ?? 0)) / 60, 2),
            'ot_weekend_hours'  => round($days->filter($offDay)->sum(fn (array $day): int => (int) ($day['overtime_minutes'] ?? 0)) / 60, 2),
            'loan_due'          => round((float) ($deductions['loan'] ?? 0), 2),
            'advance_due'       => round((float) ($deductions['advance'] ?? 0), 2),
            'notes'             => implode('; ', $notes),
        ];
    }

    /**
     * Splits the month's leave days (as the attendance report counted them)
     * into paid and unpaid by the leave type's "unpaid" flag, and counts the
     * days with a half-day leave. On-Duty is not leave.
     *
     * @param  Collection<int, array<string, mixed>>  $days
     * @param  array<int, object>  $leaves
     * @param  array<int, int>  $unpaidTypeIds
     * @return array{0: float, 1: float, 2: int} paid, unpaid, half days
     */
    protected function leaveSplit(Collection $days, array $leaves, array $unpaidTypeIds, float $totalLeave): array
    {
        $unpaid = 0.0;
        $halfDays = 0;
        $pattern = (string) config('attendance.monthly_report.on_duty_leave_type_pattern', '/\b(on[\s\-_]?duty|o\.?d\.?)\b/i');

        foreach ($days as $day) {
            $leaveDays = (float) ($day['leave_days'] ?? 0);
            if ($leaveDays <= 0) {
                continue;
            }

            $covering = array_values(array_filter($leaves, fn (object $leave): bool => ApprovedLeaveLookup::covers($leave, (string) $day['date'])
                && ! ($pattern !== '' && @preg_match($pattern, trim((string) ($leave->leave_type_name ?? ''))) === 1)));
            $isUnpaid = fn (object $leave): bool => in_array((int) ($leave->holiday_status_id ?? 0), $unpaidTypeIds, true);

            if (($day['type'] ?? null) === 'leave') {
                $full = collect($covering)->first(fn (object $leave): bool => ! ($leave->request_unit_half ?? false) && ! ($leave->request_unit_hours ?? false));
                $unpaid += $full !== null && $isUnpaid($full) ? $leaveDays : 0.0;

                continue;
            }

            $halves = collect($covering)->filter(fn (object $leave): bool => (bool) ($leave->request_unit_half ?? false));
            $hours = collect($covering)->filter(fn (object $leave): bool => ! ($leave->request_unit_half ?? false) && (bool) ($leave->request_unit_hours ?? false));

            if ($halves->isNotEmpty()) {
                $halfDays++;
            }

            $halfShare = min($leaveDays, 0.5 * $halves->count());
            $hoursShare = max(0.0, $leaveDays - $halfShare);
            $hoursTotal = $hours->sum(fn (object $leave): float => $this->leaveHours($leave));
            $hoursUnpaid = $hours->filter($isUnpaid)->sum(fn (object $leave): float => $this->leaveHours($leave));

            $unpaid += min($leaveDays, 0.5 * $halves->filter($isUnpaid)->count() + ($hoursTotal > 0 ? $hoursShare * $hoursUnpaid / $hoursTotal : 0.0));
        }

        $unpaid = round(min($unpaid, $totalLeave), 2);

        return [round(max(0.0, $totalLeave - $unpaid), 2), $unpaid, $halfDays];
    }

    /**
     * Approved leave overlapping the month per employee (the attendance
     * report's own lookup) and the leave types flagged unpaid.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{0: array<int, array<int, object>>, 1: array<int, int>}
     */
    protected function leaveContext(array $employeeIds, string $from, string $to): array
    {
        if ($employeeIds === [] || ! ApprovedLeaveLookup::available()) {
            return [[], []];
        }

        $leaves = $this->attendance->leaves($employeeIds, $from, $to);
        $typeIds = collect($leaves)->flatten(1)->pluck('holiday_status_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $unpaid = $typeIds === [] ? [] : DB::table('time_off_leave_types')
            ->whereIn('id', $typeIds)
            ->where('unpaid', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [$leaves, $unpaid];
    }

    protected function leaveHours(object $leave): float
    {
        $from = $leave->request_hour_from ?? null;
        $to = $leave->request_hour_to ?? null;

        if ($from !== null && $to !== null && (float) $to > (float) $from) {
            return (float) $to - (float) $from;
        }

        return max(0.0, (float) ($leave->number_of_hours ?? 0));
    }

    /** "Joined" / "Left" dates for the screen. */
    public static function formatDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d M Y') : '';
    }
}
