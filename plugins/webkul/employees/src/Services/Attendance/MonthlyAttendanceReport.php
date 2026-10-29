<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Webkul\Employee\Models\AttendancePolicy;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\Data\ScheduleSet;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;

/**
 * Monthly attendance report, exactly as HR's Google Sheet template computes
 * it, extended with several check-in / check-out pairs per day, half days,
 * short leave and the time bank (business rules of 2026-10-28).
 *
 * Per working day (office window attendance.monthly_report.office_start /
 * office_end, 10:00-18:00):
 *  - inside  = SUM of every interval clipped to the office window (merged, so
 *              overlaps count once; never first-in to last-out);
 *  - credit  = minutes worked before the start + after the end, across the
 *              intervals, capped at the company's daily extra-credit cap
 *              (AttendancePolicy, default max_outside_credit_minutes = 60);
 *  - final   = inside + credit (not capped at 8h);
 *  - required= daily_required_minutes minus approved leave: a half day
 *              (Time Off half day, morning = first half 10-14, afternoon =
 *              second half 14-18) excuses half, a short leave (Time Off by
 *              the hour) excuses its from-to window. An approved On-Duty
 *              (OD) window counts as worked.
 *  - due window = the office window minus the approved leave windows.
 *    late = due minutes between the due start (10:00, 14:00 after a
 *    first-half off, the end of a short leave starting at 10:00) and the
 *    first check-in; left early = due minutes between the last check-out
 *    and the due end; gap = the remaining due minutes not worked and not
 *    covered by leave or OD. No grace (late_grace_minutes, default 0).
 *  - Work done during an approved-off window (e.g. stayed after a second
 *    half day off) is real work inside 10-6: it counts in inside and is
 *    shown as extra; HR should cancel the leave if it was not taken.
 *
 * Month: worked = sum(final); required = sum(required);
 * delta = max(0, required - worked - grace allowance - carry-in);
 * penalty = delta / required.
 *
 * Time bank (grace balance) per day: + extra (credit + work during approved
 * off windows) - late - left early - gap, which is exactly final - required.
 * The running balance starts at the company's monthly grace allowance plus
 * the carry-in, so the month's closing balance = allowance + carry-in +
 * worked - required; when it is negative, -balance = delta (the shortfall
 * that drives the penalty). With no allowance the balance is simply
 * worked - required, i.e. -delta whenever there is a shortfall.
 * Carry-over (per company, default off): the previous month's earned extra
 * that was not used up (min(closing balance, earned extra), never negative,
 * one month only -- no chaining) is added to the next month's opening.
 *
 * Inputs are read, never written: intervals (employees_attendance_intervals,
 * falling back to the day's check_in / check_out for rows without intervals),
 * approved leave from Time Off (state validate_two, via ApprovedLeaveLookup),
 * public holidays from calendar_leaves.
 *
 * Every figure is kept in seconds internally; punches count to the minute.
 *
 * Work schedules (HR parity plan 2.2): the office window, required minutes
 * and working weekdays above are the day's schedule, resolved by date by
 * AttendanceScheduleResolver (an employee's effective-dated WorkSchedule, or
 * the company default = the config office hours). A schedule with a flexible
 * window lets the day slide with a later first check-in, by up to that many
 * minutes (the end slides with it); not on a day with part-day leave or OD.
 *
 * Overtime (no pay calculation): recorded extra = minutes worked (closed
 * intervals, plus OD) beyond the day's required minutes; on a weekend,
 * public holiday or full-day leave every worked minute. Approved overtime
 * (OvertimeService, Employee Requests) is excluded from that day's time
 * bank so it is never counted twice: it comes out of the minutes worked
 * outside the window first (before the daily credit cap), then out of the
 * minutes inside it. "Final working hours", the grace balance and the
 * off-day hours of that day are therefore net of approved overtime.
 *
 * Rotating rosters and overnight shifts (HR parity plan 3.4): the day's
 * schedule may be a shift from a roster or a day override (resolution order
 * in AttendanceScheduleResolver). An overnight shift (22:00-06:00) is one
 * day, the date it starts: its punches are stored on that attendance date
 * and counted in seconds after that date's midnight (a 06:00 check-out is
 * 108000), so late / left early / the month boundary all follow the start
 * date. A roster day off is an off day ("Day off") like a weekend.
 *
 * Unpaid break (HR parity plan 3.5): a schedule / shift with break minutes
 * deducts the break from the day's worked time when the employee worked
 * through the break window (centred on the middle of the day) without
 * recording it; recorded gaps between check-ins inside the day (not
 * covered by approved leave or OD) count towards the break, so a break is
 * never deducted twice. Shown as "Break deducted N min" and in
 * break_deducted_minutes (day and month).
 *
 * Finalised months (AttendanceMonthLockService): forCompany() and
 * forEmployee() read the snapshot HR froze when finalising, so the export
 * stays stable; a re-opened month is calculated live again.
 */
class MonthlyAttendanceReport
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected AttendanceIntervalService $intervals,
        protected AttendanceScheduleResolver $schedules,
    ) {}

    /** HR (view or manage attendance). Line managers hold ManageAttendance too, but only see their own people. */
    public static function canView(?User $user): bool
    {
        return $user !== null
            && ($user->can(HrPermissions::ManageAttendance) || $user->can(HrPermissions::ViewAttendance));
    }

    /**
     * Employees of the company whose attendance the user may report on.
     *
     * @throws AuthorizationException
     */
    public function reportableEmployees(User $user, int $companyId, ?int $departmentId = null): Builder
    {
        $this->authorizeCompany($user, $companyId);

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $this->hierarchy->visibleEmployeeIds($user, $companyId))
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId));
    }

    /**
     * The whole report for one company and month.
     *
     * @param  string  $month  Y-m
     * @param  array<int, int>  $employeeIds  empty = every reportable employee
     * @return array{company: array{id: int, name: string}, month: string, month_label: string, generated_at: CarbonImmutable, generated_by: ?string, policy: array<string, mixed>, finalised: ?array{at: CarbonImmutable, by: ?string}, employees: array<int, array<string, mixed>>}
     *
     * @throws AuthorizationException
     */
    public function forCompany(User $user, int $companyId, string $month, ?int $departmentId = null, array $employeeIds = []): array
    {
        $monthStart = $this->monthStart($month);
        $lock = $this->locks()->finalisedLock($companyId, $monthStart->format('Y-m'));

        if ($lock !== null) {
            // A finalised month: the frozen snapshot, limited to who this user may see.
            $visible = $this->reportableEmployees($user, $companyId, $departmentId)
                ->when($employeeIds !== [], fn (Builder $query) => $query->whereIn('id', array_map('intval', $employeeIds)))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $reports = array_values($this->locks()->snapshotsFor($companyId, $monthStart->format('Y-m'), $visible));
        } else {
            $reports = $this->build($this->monthEmployees($companyId, $monthStart, $this->reportableEmployees($user, $companyId, $departmentId)
                ->when($employeeIds !== [], fn (Builder $query) => $query->whereIn('id', array_map('intval', $employeeIds)))), $companyId, $monthStart);
        }

        return [
            'company'      => $this->companyInfo($companyId),
            'month'        => $monthStart->format('Y-m'),
            'month_label'  => $monthStart->format('F Y'),
            'generated_at' => CarbonImmutable::now($this->timezone()),
            'generated_by' => $user->name,
            'policy'       => $this->policyFor($companyId),
            'finalised'    => $lock !== null ? [
                'at' => CarbonImmutable::parse($lock->finalised_at)->setTimezone($this->timezone()),
                'by' => $lock->finaliser?->name,
            ] : null,
            'employees'    => $reports,
        ];
    }

    /**
     * Every employee of the company in the month, calculated live (never the
     * snapshot, no user scope): what AttendanceMonthLockService freezes when
     * HR finalises the month.
     *
     * @return array<int, array{employee: array<string, mixed>, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}>
     */
    public function liveCompanyMonth(int $companyId, string $month): array
    {
        $monthStart = $this->monthStart($month);

        return $this->build($this->monthEmployees($companyId, $monthStart, Employee::query()->where('company_id', $companyId)), $companyId, $monthStart);
    }

    /** How many employees liveCompanyMonth() reports on. */
    public function companyMonthEmployeeCount(int $companyId, string $month): int
    {
        return $this->monthEmployees($companyId, $this->monthStart($month), Employee::query()->where('company_id', $companyId))->count();
    }

    /**
     * One employee's month (no user authorisation: callers authorise). An
     * employee of another company is refused. A finalised month returns the
     * employee's snapshot when there is one.
     *
     * @return array{employee: array<string, mixed>, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function forEmployee(Employee $employee, int $companyId, string $month): array
    {
        if ((int) $employee->company_id !== $companyId) {
            throw new AuthorizationException('This employee does not belong to the selected company.');
        }

        $monthStart = $this->monthStart($month);
        $snapshot = $this->locks()->snapshotsFor($companyId, $monthStart->format('Y-m'), [(int) $employee->id])[(int) $employee->id] ?? null;
        if ($snapshot !== null) {
            return $snapshot;
        }

        return $this->build(collect([$employee->loadMissing(['department', 'job'])]), $companyId, $monthStart)[0];
    }

    /**
     * One day of one employee, calculated live with the same inputs as the
     * month (schedule, intervals, leave, holidays, approved overtime). Used
     * for the overtime request's recorded extra minutes.
     *
     * @return array<string, mixed> calculateDay() plus date, schedule and overtime_pending_minutes
     */
    public function dayRow(Employee $employee, string $date): array
    {
        $companyId = (int) $employee->company_id;
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();
        $key = $day->toDateString();
        $employeeIds = [(int) $employee->id];

        $context = [
            'punches'  => $this->punches($companyId, $employeeIds, $key, $key, $this->timezone()),
            'leaves'   => $this->leaves($employeeIds, $key, $key),
            'holidays' => $this->holidays($companyId, $key, $key),
            'periods'  => $this->schedules->setsFor([$employee], $key, $key),
            'overtime' => OvertimeService::minutesByDay($companyId, $employeeIds, $key, $key),
            'policy'   => $this->policyFor($companyId),
            'today'    => CarbonImmutable::now($this->timezone())->toDateString(),
        ];

        return ['date' => $key] + $this->calculateEmployeeDay($employee, $key, $context);
    }

    /**
     * Employees of the company to report on for the month: employed during
     * it, active or departed.
     *
     * @return Collection<int, Employee>
     */
    protected function monthEmployees(int $companyId, CarbonImmutable $monthStart, Builder $query): Collection
    {
        return $query
            ->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $monthStart->toDateString()))
            ->where(fn (Builder $query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $monthStart->endOfMonth()->toDateString()))
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNotNull('departure_date'))
            ->with(['department', 'job'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    protected function locks(): AttendanceMonthLockService
    {
        return app(AttendanceMonthLockService::class);
    }

    /**
     * The month of the given employees of one company, without a user (the
     * scheduled employee reminders; callers decide who is told). Employees
     * of another company are ignored.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{employee: array<string, mixed>, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}> keyed by employee id
     */
    public function forEmployeesOfCompany(int $companyId, string $month, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', $employeeIds))
            ->with(['department', 'job'])
            ->orderBy('id')
            ->get();

        return collect($this->build($employees, $companyId, $this->monthStart($month)))
            ->keyBy(fn (array $report): int => (int) $report['employee']['id'])
            ->all();
    }

    /**
     * The employee's grace balance ledger for a month: allowance, earned
     * extra, used (late + left early + gaps), balance and one line per day.
     * The employee sees their own; a line manager their team; HR their
     * company (the same visibility as the monthly report).
     *
     * @return array{employee: array<string, mixed>, month: string, month_label: string, policy: array<string, mixed>, allowance_minutes: int, carry_in_minutes: int, earned_minutes: int, late_minutes: int, left_early_minutes: int, gap_minutes: int, used_minutes: int, balance_minutes: int, summary: array<string, mixed>, rows: array<int, array<string, mixed>>}
     *
     * @throws AuthorizationException
     */
    public function graceLedger(User $viewer, Employee $employee, string $month): array
    {
        if (! $this->canSeeLedger($viewer, $employee)) {
            throw new AuthorizationException('You may not view this employee\'s grace balance.');
        }

        $report = $this->forEmployee($employee, (int) $employee->company_id, $month);
        $summary = $report['summary'];
        $monthStart = $this->monthStart($month);

        return [
            'employee'           => $report['employee'],
            'month'              => $monthStart->format('Y-m'),
            'month_label'        => $monthStart->format('F Y'),
            'policy'             => $this->policyFor((int) $employee->company_id),
            'allowance_minutes'  => $summary['grace_allowance_minutes'],
            'carry_in_minutes'   => $summary['grace_carry_in_minutes'],
            'earned_minutes'     => $summary['time_bank_extra_minutes'],
            'late_minutes'       => $summary['late_minutes'],
            'left_early_minutes' => $summary['left_early_minutes'],
            'gap_minutes'        => $summary['gap_minutes'],
            'used_minutes'       => $summary['late_minutes'] + $summary['left_early_minutes'] + $summary['gap_minutes'],
            'balance_minutes'    => $summary['grace_balance_minutes'],
            'summary'            => $summary,
            'rows'               => collect($report['rows'])
                ->filter(fn (array $row): bool => $row['working'] || $row['ledger_seconds'] !== 0)
                ->map(fn (array $row): array => [
                    'date'               => $row['date'],
                    'check_ins'          => $row['intervals_text'],
                    'remarks'            => $row['remarks'],
                    'extra_minutes'      => $row['extra_minutes'],
                    'late_minutes'       => $row['late_minutes'],
                    'left_early_minutes' => $row['left_early_minutes'],
                    'gap_minutes'        => $row['gap_minutes'],
                    'change_minutes'     => intdiv($row['ledger_seconds'], 60),
                    'balance_minutes'    => intdiv($row['grace_balance_seconds'], 60),
                ])
                ->values()
                ->all(),
        ];
    }

    public function canSeeLedger(User $viewer, Employee $employee): bool
    {
        if ((int) $employee->user_id === (int) $viewer->id && $employee->user_id !== null) {
            return true;
        }

        $companyId = (int) $employee->company_id;

        return static::canView($viewer)
            && CurrentCompany::canAccess($companyId, $viewer)
            && $this->hierarchy->visibleEmployeeIds($viewer, $companyId)->contains((int) $employee->id);
    }

    /** @return array{monthly_grace_minutes: int, daily_extra_credit_cap_minutes: int, carry_over: bool} */
    public function policyFor(int $companyId): array
    {
        $policy = AttendancePolicy::forCompany($companyId);

        return [
            'monthly_grace_minutes'          => (int) $policy->monthly_grace_minutes,
            'daily_extra_credit_cap_minutes' => (int) $policy->daily_extra_credit_cap_minutes,
            'carry_over'                     => (bool) $policy->carry_over,
        ];
    }

    /**
     * The template's arithmetic for one day. Times are seconds after the
     * day's local midnight (a check-out after midnight is > 86400).
     *
     * intervals is a list of [in, out|null] pairs; without it, in / out are
     * the day's single pair (as before intervals existed).
     * od is null | full | first_half | second_half; a leave portion is full |
     * first_half | second_half | hours (with an optional window [from, to] in
     * seconds and an approver name). leaves lists several part-day leaves.
     * schedule is the day's window (seconds), required and flexible minutes
     * (DaySchedule::forCalculation(); default: the config office hours), with
     * an optional unpaid break (minutes, see the class docs) and an end above
     * 86400 for an overnight shift; overtime_minutes is the day's approved
     * overtime (see the class docs); off_label replaces "Weekend" on a
     * roster day off.
     *
     * @param  array{in?: ?int, out?: ?int, intervals?: array<int, array{0: int, 1: ?int}>, missing_check_out?: bool, weekend?: bool, holiday?: ?string, employed?: bool, future?: bool, od?: ?string, leave?: ?array{name: string, portion: string, hours?: float, window?: ?array{0: int, 1: int}, approver?: ?string}, leaves?: array<int, array<string, mixed>>, credit_cap_minutes?: int, schedule?: array{start: int, end: int, required: int, flexible?: int, break?: int}, overtime_minutes?: int, off_label?: ?string}  $day
     * @return array<string, mixed>
     */
    public function calculateDay(array $day): array
    {
        $settings = $this->settings();
        $start = (int) ($day['schedule']['start'] ?? $settings['start']);
        $end = (int) ($day['schedule']['end'] ?? $settings['end']);
        $requiredMinutes = (int) ($day['schedule']['required'] ?? $settings['required']);
        $flexibleSeconds = max(0, (int) ($day['schedule']['flexible'] ?? 0)) * 60;
        $required = $requiredMinutes * 60;
        $creditCap = (int) ($day['credit_cap_minutes'] ?? $settings['credit']) * 60;

        [$pairs, $in, $out, $missingCheckIn] = $this->normalisePairs($day);
        $od = $day['od'] ?? null;
        $parts = array_values(array_filter($day['leaves'] ?? (isset($day['leave']) && $day['leave'] !== null ? [$day['leave']] : [])));
        $fullLeave = collect($parts)->first(fn (array $part): bool => $part['portion'] === 'full');
        $remarks = [];

        // Flexible schedule: a later first check-in moves the whole day, up to the flexible window.
        if ($flexibleSeconds > 0 && $in !== null && $in > $start && $parts === [] && $od === null) {
            $shift = min($flexibleSeconds, $in - $start);
            $start += $shift;
            $end += $shift;
        }
        $middle = $start + intdiv($end - $start, 2);

        $type = 'working';
        if (! ($day['employed'] ?? true)) {
            $type = 'not_employed';
            $remarks[] = 'Not employed';
        } elseif ($day['future'] ?? false) {
            $type = 'future';
        } elseif ($day['weekend'] ?? false) {
            $type = 'weekend';
            $remarks[] = ($day['off_label'] ?? null) ?: 'Weekend';
        } elseif (($day['holiday'] ?? null) !== null) {
            $type = 'holiday';
            $remarks[] = 'Public holiday'.($day['holiday'] !== '' ? ': '.$day['holiday'] : '');
        } elseif ($fullLeave !== null) {
            $type = 'leave';
            $remarks[] = $fullLeave['name'];
        }

        $isWorking = $type === 'working';
        $excusedSeconds = 0;
        $leaveWindows = [];

        if ($isWorking) {
            foreach ($parts as $part) {
                $window = match ($part['portion']) {
                    'first_half'  => [$start, $middle],
                    'second_half' => [$middle, $end],
                    'hours'       => isset($part['window']) && $part['window'] !== null ? [max($start, $part['window'][0]), min($end, $part['window'][1])] : null,
                    default       => null,
                };
                if ($window !== null && $window[1] > $window[0]) {
                    $leaveWindows[] = $window;
                }

                $excusedSeconds += match ($part['portion']) {
                    'first_half', 'second_half' => intdiv($required, 2),
                    'hours'                     => $window !== null ? max(0, $window[1] - $window[0]) : (int) round(((float) ($part['hours'] ?? 0)) * 3600),
                    default                     => 0,
                };

                $remarks[] = $part['name'].match ($part['portion']) {
                    'first_half'  => ' - first half',
                    'second_half' => ' - second half',
                    'hours'       => isset($part['window']) && $part['window'] !== null
                        ? ' '.$this->clock($part['window'][0]).'–'.$this->clock($part['window'][1])
                        : ' ('.rtrim(rtrim(number_format((float) ($part['hours'] ?? 0), 2), '0'), '.').'h)',
                    default       => '',
                }.(! empty($part['approver']) ? ' (approved: '.$part['approver'].')' : '');
            }
        }
        $excusedSeconds = min($excusedSeconds, $required);

        $odInterval = $isWorking ? match ($od) {
            'full'        => [$start, $end],
            'first_half'  => [$start, $middle],
            'second_half' => [$middle, $end],
            default       => null,
        } : null;

        if ($odInterval !== null) {
            $remarks[] = match ($od) {
                'full'        => 'On-Duty',
                'first_half'  => 'Half OD - first half',
                default       => 'Half OD - second half',
            };
        }

        $closed = array_values(array_filter($pairs, fn (array $pair): bool => $pair[1] !== null && $pair[1] > $pair[0]));
        $hasOpen = collect($pairs)->contains(fn (array $pair): bool => $pair[1] === null);
        $missingCheckOut = $hasOpen;
        $hasPair = $closed !== [];

        $work = $closed;
        if ($odInterval !== null) {
            $work[] = $odInterval;
        }

        // Unpaid break: deducted once from a day worked through the break window without a recorded break.
        $breakSeconds = max(0, (int) ($day['schedule']['break'] ?? 0)) * 60;
        $breakDeducted = $this->breakDeduction($closed, $start, $end, $breakSeconds, $odInterval !== null ? [...$leaveWindows, $odInterval] : $leaveWindows);

        $inside = $this->unionWithin($work, $start, $end);
        $breakDeducted = min($breakDeducted, $inside);
        $inside -= $breakDeducted;
        $before = 0;
        $after = 0;
        foreach ($closed as [$pairIn, $pairOut]) {
            $before += max(0, min($pairOut, $start) - $pairIn);
            $after += max(0, $pairOut - max($pairIn, $end));
        }
        $outside = $before + $after;
        $requiredSeconds = $isWorking ? max(0, $required - $excusedSeconds) : 0;

        // Overtime: recorded extra, and approved overtime taken out of the time bank (outside first, then inside).
        $workedTotal = $inside + $outside;
        $recordedExtra = match (true) {
            $isWorking                                             => max(0, $workedTotal - $requiredSeconds),
            in_array($type, ['weekend', 'holiday', 'leave'], true) => $workedTotal,
            default                                                => 0,
        };
        $overtime = min(max(0, (int) ($day['overtime_minutes'] ?? 0)) * 60, $recordedExtra);
        $overtimeOutside = min($overtime, $outside);
        $overtimeInside = $overtime - $overtimeOutside;

        $credit = min($creditCap, $outside - $overtimeOutside);
        $final = $inside - $overtimeInside + $credit;

        $lastClosedOut = $hasPair ? max(array_column($closed, 1)) : null;
        $clampedIn = $in !== null ? max($in, $start) : null;
        $clampedOut = $hasPair ? min($lastClosedOut, $end) : null;
        if ($odInterval !== null) {
            $clampedIn = $clampedIn === null ? $odInterval[0] : min($clampedIn, $odInterval[0]);
            $clampedOut = $clampedOut === null ? $odInterval[1] : max($clampedOut, $odInterval[1]);
        }

        $hasPunch = $in !== null || $out !== null;

        $absent = $isWorking && $requiredSeconds > 0 && ! $hasPunch && $odInterval === null;
        if ($absent) {
            $remarks[] = 'Absent';
        }
        if ($missingCheckOut && $isWorking) {
            $remarks[] = 'Missing check-out';
        } elseif ($missingCheckOut) {
            $remarks[] = 'Missing check-out';
        }
        if ($missingCheckIn) {
            $remarks[] = 'Missing check-in';
        }

        // Time bank: late / left early / gap inside the due window (office minus approved leave).
        $lateSeconds = 0;
        $earlySeconds = 0;
        $workedInLeave = 0;
        $dueStartSeconds = null;
        if ($isWorking && $requiredSeconds > 0) {
            $due = $this->subtract([[$start, $end]], $leaveWindows);
            $odCover = $odInterval !== null ? [$odInterval] : [];
            $expected = $this->subtract($due, $odCover);
            $dueStartSeconds = $expected !== [] ? $expected[0][0] : null;

            if ($due !== []) {
                $dueStart = $due[0][0];
                $dueEnd = $due[count($due) - 1][1];

                if ($in !== null) {
                    $lateSeconds = $this->measure($this->subtract($this->intersect($due, [[$dueStart, $in]]), $odCover));
                }
                if ($lastClosedOut !== null && ! $hasOpen) {
                    $earlySeconds = $this->measure($this->subtract($this->intersect($due, [[$lastClosedOut, $dueEnd]]), $odCover));
                }
            }

            $workedInLeave = max(0, $inside - $this->measure($this->intersect($due, $this->merge($work))) - $overtimeInside);
        }

        $netSeconds = $isWorking ? $final - $requiredSeconds : 0;
        $extraSeconds = $isWorking ? $credit + $workedInLeave : 0;
        $gapSeconds = $extraSeconds - $lateSeconds - $earlySeconds - $netSeconds;
        if ($gapSeconds < 0) {
            // Only reachable with legacy hour-only leave (no window): keep extra - late - early - gap = final - required.
            $extraSeconds -= $gapSeconds;
            $gapSeconds = 0;
        }

        $lateMinutes = intdiv($lateSeconds, 60);
        $late = $isWorking && $in !== null && $od !== 'full' && $lateMinutes > $settings['grace'];
        if ($late) {
            $remarks[] = 'Late';
        }
        $earlyMinutes = intdiv($earlySeconds, 60);
        if ($isWorking && $earlyMinutes > 0) {
            $remarks[] = 'Left early';
        }
        $gapMinutes = intdiv($gapSeconds, 60);
        if ($isWorking && $gapMinutes > 0 && ! $absent && ! $missingCheckOut) {
            $remarks[] = 'Away without approval '.$gapMinutes.' min';
        }
        if ($isWorking && $workedInLeave >= 60) {
            $remarks[] = 'Worked during approved leave';
        }
        if ($breakDeducted >= 60) {
            $remarks[] = 'Break deducted '.intdiv($breakDeducted, 60).' min';
        }

        $offDayWork = in_array($type, ['weekend', 'holiday', 'leave'], true) && $hasPair;
        if ($offDayWork) {
            $remarks[] = 'Worked';
        }

        return [
            'type'                    => $type,
            'working'                 => $isWorking,
            'first_in'                => $in,
            'last_out'                => $out,
            'intervals'               => $pairs,
            'intervals_text'          => $this->intervalsText($pairs),
            'remarks'                 => implode('; ', array_unique($remarks)),
            'clamped_in'              => ($isWorking || $hasPair) ? $clampedIn : null,
            'clamped_out'             => ($isWorking || $hasPair) ? $clampedOut : null,
            'before_minutes'          => intdiv($before, 60),
            'after_minutes'           => intdiv($after, 60),
            'inside_seconds'          => $inside,
            'credit_minutes'          => intdiv($credit, 60),
            'credit_seconds'          => $credit,
            'final_seconds'           => $final,
            'required_seconds'        => $requiredSeconds,
            'present'                 => $isWorking && ($hasPunch || $odInterval !== null),
            'absent'                  => $absent,
            'late'                    => $late,
            'late_minutes'            => $isWorking ? $lateMinutes : 0,
            'left_early_minutes'      => $isWorking ? $earlyMinutes : 0,
            'gap_minutes'             => $isWorking ? $gapMinutes : 0,
            'extra_minutes'           => intdiv($extraSeconds, 60),
            'worked_in_leave_seconds' => $workedInLeave,
            'net_seconds'             => $netSeconds,
            'missing_check_out'       => $isWorking && $missingCheckOut,
            // When the employee is first expected at work (office start, 14:00 after a first half off, ...); null = not expected.
            'due_start_seconds'       => $dueStartSeconds,
            'od'                      => $odInterval !== null ? $od : null,
            'od_days'                 => $odInterval === null ? 0.0 : ($od === 'full' ? 1.0 : 0.5),
            'leave_days'              => $this->leaveDays($type, $isWorking, $parts, $requiredMinutes),
            'off_day_work'            => $offDayWork,
            'off_day_seconds'         => $offDayWork ? $final : 0,
            // Every minute worked on a weekend / public holiday (before any overtime is taken out).
            'weekend_holiday_seconds' => in_array($type, ['weekend', 'holiday'], true) ? $workedTotal : 0,
            'worked_total_seconds'    => $workedTotal,
            'recorded_extra_minutes'  => intdiv($recordedExtra, 60),
            'overtime_minutes'        => intdiv($overtime, 60),
            // The day's window after a flexible start (seconds after midnight).
            'schedule_start_seconds'  => $start,
            'schedule_end_seconds'    => $end,
            // Unpaid break of the day's schedule, and how much of it came off the worked time (already net in inside / final).
            'break_minutes'           => intdiv($breakSeconds, 60),
            'break_deducted_minutes'  => intdiv($breakDeducted, 60),
        ];
    }

    /**
     * The unpaid break to take off a day's worked time: none unless the
     * closed intervals run from before to after the break window (centred on
     * the middle of the day); then the break minus the gaps already recorded
     * between check-ins inside the day, not counting gaps covered by
     * approved leave or OD ($excused).
     *
     * @param  array<int, array{0: int, 1: int}>  $closed
     * @param  array<int, array{0: int, 1: int}>  $excused
     */
    protected function breakDeduction(array $closed, int $start, int $end, int $breakSeconds, array $excused): int
    {
        if ($breakSeconds <= 0 || $closed === []) {
            return 0;
        }

        $merged = $this->merge($closed);
        $breakStart = $start + intdiv($end - $start, 2) - intdiv($breakSeconds, 2);
        $breakEnd = $breakStart + $breakSeconds;

        if ($merged[0][0] > $breakStart || $merged[count($merged) - 1][1] < $breakEnd) {
            return 0;
        }

        $gaps = [];
        for ($index = 1; $index < count($merged); $index++) {
            $gaps[] = [$merged[$index - 1][1], $merged[$index][0]];
        }

        $recorded = $this->measure($this->subtract($this->intersect($gaps, [[$start, $end]]), $excused));

        return max(0, $breakSeconds - $recorded);
    }

    /**
     * Month totals of calculated rows. $allowanceMinutes / $carryInMinutes
     * open the grace balance (see the class docs).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarize(array $rows, int $allowanceMinutes = 0, int $carryInMinutes = 0): array
    {
        $countOffDayWork = (bool) config('attendance.monthly_report.count_off_day_work', false);
        $rows = collect($rows);
        $working = $rows->where('working', true);

        $offDaySeconds = (int) $rows->sum('off_day_seconds');
        $workedSeconds = (int) $working->sum('final_seconds') + ($countOffDayWork ? $offDaySeconds : 0);
        $requiredSeconds = (int) $working->sum('required_seconds');
        $extraSeconds = (int) $working->sum(fn (array $row): int => max(0, $row['final_seconds'] - $row['required_seconds']))
            + ($countOffDayWork ? 0 : $offDaySeconds);

        $graceSeconds = ($allowanceMinutes + $carryInMinutes) * 60;
        $workedHours = round($workedSeconds / 3600, 2);
        $requiredHours = round($requiredSeconds / 3600, 2);
        $delta = round(max(0, $requiredHours - $workedHours - $graceSeconds / 3600), 2);

        $lateMinutes = (int) $working->sum('late_minutes');
        $leftEarlyMinutes = (int) $working->sum(fn (array $row): int => (int) ($row['left_early_minutes'] ?? 0));
        $gapMinutes = (int) $working->sum(fn (array $row): int => (int) ($row['gap_minutes'] ?? 0));
        $bankExtraMinutes = (int) $working->sum(fn (array $row): int => (int) ($row['extra_minutes'] ?? 0))
            + ($countOffDayWork ? intdiv($offDaySeconds, 60) : 0);
        $netBalanceMinutes = (int) round(($workedSeconds - $requiredSeconds) / 60);
        $overtimeApprovedMinutes = (int) $rows->sum(fn (array $row): int => (int) ($row['overtime_minutes'] ?? 0));
        $overtimePendingMinutes = (int) $rows->sum(fn (array $row): int => (int) ($row['overtime_pending_minutes'] ?? 0));
        $weekendHolidaySeconds = (int) $rows->sum(fn (array $row): int => (int) ($row['weekend_holiday_seconds'] ?? 0));

        return [
            'working_days'            => $working->where('required_seconds', '>', 0)->count(),
            'present_days'            => $working->where('present', true)->count(),
            'absent_days'             => $working->where('absent', true)->count(),
            'leave_days'              => round((float) $rows->sum('leave_days'), 2),
            'od_days'                 => round((float) $rows->sum('od_days'), 2),
            'holidays'                => $rows->where('type', 'holiday')->count(),
            'late_days'               => $working->where('late', true)->count(),
            'late_minutes'            => $lateMinutes,
            'left_early_minutes'      => $leftEarlyMinutes,
            'gap_minutes'             => $gapMinutes,
            'missing_check_outs'      => $working->where('missing_check_out', true)->count(),
            'worked_seconds'          => $workedSeconds,
            'required_seconds'        => $requiredSeconds,
            'worked_hours'            => $workedHours,
            'required_hours'          => $requiredHours,
            'delta_hours'             => $delta,
            'penalty_percent'         => $requiredHours > 0 ? round($delta / $requiredHours * 100, 2) : 0.0,
            'extra_minutes'           => intdiv($extraSeconds, 60),
            'off_day_work_hours'      => round($offDaySeconds / 3600, 2),
            // Time bank: extra - late - left early - gap = worked - required (minutes).
            'time_bank_extra_minutes' => $bankExtraMinutes,
            'net_balance_minutes'     => $netBalanceMinutes,
            'grace_allowance_minutes' => $allowanceMinutes,
            'grace_carry_in_minutes'  => $carryInMinutes,
            'grace_balance_minutes'   => $allowanceMinutes + $carryInMinutes + $netBalanceMinutes,
            // Overtime (no pay calculation): approved minutes are already out of the time bank above.
            'recorded_extra_minutes'    => (int) $rows->sum(fn (array $row): int => (int) ($row['recorded_extra_minutes'] ?? 0)),
            'overtime_approved_minutes' => $overtimeApprovedMinutes,
            'overtime_approved_hours'   => round($overtimeApprovedMinutes / 60, 2),
            'overtime_pending_minutes'  => $overtimePendingMinutes,
            'overtime_pending_hours'    => round($overtimePendingMinutes / 60, 2),
            'weekend_holiday_seconds'   => $weekendHolidaySeconds,
            'weekend_holiday_hours'     => round($weekendHolidaySeconds / 3600, 2),
            // Unpaid break taken off worked time (already net in worked_seconds / worked_hours).
            'break_deducted_minutes'    => (int) $rows->sum(fn (array $row): int => (int) ($row['break_deducted_minutes'] ?? 0)),
        ];
    }

    /**
     * Template column headers, worded from the configured office window
     * (at the defaults this is the sheet's exact wording), or from the
     * employee's schedule (an employee report's "schedule": start / end).
     *
     * @param  array{start?: string, end?: string}|null  $schedule
     * @return array<int, string>
     */
    public function dailyHeaders(?int $creditMinutes = null, ?array $schedule = null): array
    {
        $settings = $this->settings();
        $toSeconds = fn (string $time): int => (int) explode(':', $time)[0] * 3600 + (int) (explode(':', $time)[1] ?? 0) * 60;
        $start = isset($schedule['start']) ? $toSeconds((string) $schedule['start']) : $settings['start'];
        $end = isset($schedule['end']) ? $toSeconds((string) $schedule['end']) : $settings['end'];
        $credit = $creditMinutes ?? $settings['credit'];

        $short = fn (int $seconds): string => ltrim(CarbonImmutable::today()->addSeconds($seconds)->format(intdiv($seconds % 3600, 60) === 0 ? 'g' : 'g:i'));
        $meridiem = fn (int $seconds): string => $short($seconds).' '.CarbonImmutable::today()->addSeconds($seconds)->format('A');
        $window = $short($start).'-'.$short($end);
        $creditLabel = $credit % 60 === 0 ? (intdiv($credit, 60)).' hr/'.$credit.' min' : $credit.' min';
        $creditWords = $credit % 60 === 0 ? intdiv($credit, 60).' hour'.(intdiv($credit, 60) === 1 ? '' : 's') : $credit.' min';

        return [
            'Date',
            'First In',
            'Last Out',
            'Remarks',
            'Checkin with '.$short($start).' or after',
            'Checkout with '.$short($end).' or before',
            'starting time before '.$meridiem($start).' (mins)',
            'Ending time after '.$meridiem($end).' (mins)',
            'Total hours b/w '.$window,
            $creditLabel.' outside '.$window,
            'Final working hours (max '.$creditWords.' additional given outside '.$window.')',
            'Total Hrs Worked',
            'Total Hrs Req',
            'Delta',
            'Penalty',
        ];
    }

    /**
     * Columns added after the template's 15 (never between them).
     *
     * @return array<int, string>
     */
    public function extraHeaders(): array
    {
        return [
            'Check-ins', 'Late (mins)', 'Left early (mins)', 'Extra (mins)', 'Away without approval (mins)', 'Grace balance (mins)',
            'Schedule', 'OT approved (mins)', 'OT pending (mins)', 'Break deducted (mins)',
        ];
    }

    public function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{employee: array<string, mixed>, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}>
     */
    protected function build(Collection $employees, int $companyId, CarbonImmutable $monthStart, bool $withCarry = true): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $timezone = $this->timezone();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $from = $monthStart->toDateString();
        $to = $monthEnd->toDateString();
        $employeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $policy = $this->policyFor($companyId);
        $countOffDayWork = (bool) config('attendance.monthly_report.count_off_day_work', false);
        $showOffDays = (bool) config('attendance.monthly_report.show_off_days', false);

        $context = [
            'punches'  => $this->punches($companyId, $employeeIds, $from, $to, $timezone),
            'leaves'   => $this->leaves($employeeIds, $from, $to),
            'holidays' => $this->holidays($companyId, $from, $to),
            'periods'  => $this->schedules->setsFor($employees, $from, $to),
            'overtime' => OvertimeService::minutesByDay($companyId, $employeeIds, $from, $to),
            'policy'   => $policy,
            'today'    => CarbonImmutable::now($timezone)->toDateString(),
        ];

        $carryIn = $withCarry && $policy['carry_over'] ? $this->carryIn($employees, $companyId, $monthStart) : [];

        $reports = [];

        foreach ($employees as $employee) {
            $rows = [];
            $allowance = $policy['monthly_grace_minutes'];
            $carry = (int) ($carryIn[$employee->id] ?? 0);
            $running = ($allowance + $carry) * 60;
            $scheduleDays = [];
            $schedulesByKey = [];

            for ($date = $monthStart; $date->lte($monthEnd); $date = $date->addDay()) {
                $key = $date->toDateString();
                $row = $this->calculateEmployeeDay($employee, $key, $context);

                // Running grace balance: working days move it by final - required; off-day work only when it counts.
                $change = $row['working'] ? $row['net_seconds'] : ($countOffDayWork ? $row['off_day_seconds'] : 0);
                $running += $change;
                $row['ledger_seconds'] = $change;
                $row['grace_balance_seconds'] = $running;

                if ($row['working']) {
                    $scheduleDays[$row['schedule_key']] = ($scheduleDays[$row['schedule_key']] ?? 0) + 1;
                    $schedulesByKey[$row['schedule_key']] ??= $this->schedules->pick($context['periods'][$employee->id] ?? [], $key);
                }

                // Like the template: working days, weekday leave and holidays; weekends only when worked (or configured).
                $visible = $row['working']
                    || $row['off_day_work']
                    || in_array($row['type'], ['leave', 'holiday'], true)
                    || ($showOffDays && $row['type'] === 'weekend');

                if (! $visible) {
                    continue;
                }

                $rows[] = ['date' => $key] + $row;
            }

            // The schedule of most of the month's working days (headers of the employee's sheet).
            arsort($scheduleDays);
            $mainSchedule = $schedulesByKey[(string) array_key_first($scheduleDays)] ?? $this->schedules->defaultSchedule();

            $reports[] = [
                'employee' => [
                    'id'          => (int) $employee->id,
                    'code'        => (string) ($employee->employee_number ?: $employee->barcode ?: ''),
                    'name'        => (string) $employee->name,
                    'department'  => (string) ($employee->department?->name ?? ''),
                    'designation' => (string) ($employee->job_title ?: $employee->job?->name ?: ''),
                ],
                'schedule' => $mainSchedule->toArray() + ['changes_in_month' => count($scheduleDays) > 1],
                'rows'     => $rows,
                'summary'  => $this->summarize($rows, $allowance, $carry),
            ];
        }

        return $reports;
    }

    /**
     * One employee's day with the month's preloaded inputs.
     *
     * @param  array{punches: array<int, array<string, mixed>>, leaves: array<int, array<int, object>>, holidays: Collection<int, CalendarLeave>, periods: array<int, ScheduleSet>, overtime: array{approved: array<int, array<string, int>>, pending: array<int, array<string, int>>}, policy: array<string, mixed>, today: string}  $context
     * @return array<string, mixed>
     */
    protected function calculateEmployeeDay(Employee $employee, string $key, array $context): array
    {
        $employeeId = (int) $employee->id;
        $schedule = $this->schedules->pick($context['periods'][$employeeId] ?? [], $key);
        $punch = $context['punches'][$employeeId][$key] ?? null;
        [$od, $parts] = $this->dayLeave($context['leaves'][$employeeId] ?? [], $key);

        $row = $this->calculateDay([
            'intervals'          => $punch['intervals'] ?? [],
            'weekend'            => ! $schedule->isWorkingDay($key),
            'holiday'            => $this->holidayOn($context['holidays'], $key, $employee->calendar_id ? (int) $employee->calendar_id : null),
            'employed'           => $this->isEmployedOn($employee, $key),
            'future'             => $key > $context['today'],
            'od'                 => $od,
            'leaves'             => $parts,
            'credit_cap_minutes' => $context['policy']['daily_extra_credit_cap_minutes'],
            'schedule'           => $schedule->forCalculation(),
            'overtime_minutes'   => (int) ($context['overtime']['approved'][$employeeId][$key] ?? 0),
            'off_label'          => $schedule->isOff() ? 'Day off' : null,
        ]);

        $row['overtime_pending_minutes'] = (int) ($context['overtime']['pending'][$employeeId][$key] ?? 0);
        $row['schedule'] = $schedule->label();
        $row['schedule_key'] = $schedule->key();
        $row['shift_code'] = $schedule->shiftCode;
        $row['schedule_source'] = $schedule->source;

        return $row;
    }

    /**
     * Unused earned extra of the previous month per employee (carry-over):
     * from the snapshot when the previous month is finalised, else live.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, int> employee id => minutes
     */
    protected function carryIn(Collection $employees, int $companyId, CarbonImmutable $monthStart): array
    {
        $previousMonth = $monthStart->subMonthNoOverflow()->startOfMonth();
        $carry = fn (array $summary): int => max(0, min((int) $summary['grace_balance_minutes'], (int) $summary['time_bank_extra_minutes']));
        $carryIn = [];

        $snapshots = $this->locks()->snapshotsFor($companyId, $previousMonth->format('Y-m'), $employees->pluck('id')->map(fn ($id): int => (int) $id)->all());
        foreach ($snapshots as $employeeId => $snapshot) {
            $carryIn[$employeeId] = $carry($snapshot['summary']);
        }

        $live = $employees->reject(fn (Employee $employee): bool => array_key_exists((int) $employee->id, $snapshots))->values();
        foreach ($this->build($live, $companyId, $previousMonth, false) as $previous) {
            $carryIn[$previous['employee']['id']] = $carry($previous['summary']);
        }

        return $carryIn;
    }

    /**
     * The day's check-in / check-out intervals per employee per local day,
     * in seconds after local midnight, to the minute.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, array{intervals: array<int, array{0: int, 1: ?int}>}>>
     */
    protected function punches(int $companyId, array $employeeIds, string $from, string $to, string $timezone): array
    {
        $result = [];

        $records = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->orderBy('check_in')
            ->get(['id', 'employee_id', 'attendance_date', 'check_in', 'check_out']);

        $intervals = $this->intervals->intervalsForRecords($records->pluck('id')->map(fn ($id): int => (int) $id)->all());

        foreach ($records as $record) {
            $date = $record->attendance_date->toDateString();
            $midnight = CarbonImmutable::parse($date, $timezone)->startOfDay();
            // Punches count to the minute (seconds dropped), as in HR's sheet: 10:20:45 → 10:20.
            $seconds = fn ($moment): ?int => $moment
                ? (int) floor($midnight->diffInSeconds(CarbonImmutable::parse($moment)->setTimezone($timezone), false) / 60) * 60
                : null;

            $pairs = ($intervals[$record->id] ?? collect())
                ->map(fn ($interval): array => [$seconds($interval->check_in), $seconds($interval->check_out)])
                ->all();

            if ($pairs === []) {
                if ($record->check_in === null && $record->check_out === null) {
                    continue;
                }
                $pairs = [[$seconds($record->check_in), $seconds($record->check_out)]];
            }

            $result[$record->employee_id][$date]['intervals'] = array_merge($result[$record->employee_id][$date]['intervals'] ?? [], array_values($pairs));
        }

        return $result;
    }

    /**
     * Approved Time Off (with its leave type name and final approver) overlapping the month, per employee.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, object>>
     */
    public function leaves(array $employeeIds, string $from, string $to): array
    {
        if (! ApprovedLeaveLookup::available()) {
            return [];
        }

        $query = DB::table('time_off_leaves')
            ->leftJoin('time_off_leave_types', 'time_off_leave_types.id', '=', 'time_off_leaves.holiday_status_id')
            ->whereIn('time_off_leaves.employee_id', $employeeIds)
            ->where('time_off_leaves.state', ApprovedLeaveLookup::APPROVED_STATE)
            ->select('time_off_leaves.*', 'time_off_leave_types.name as leave_type_name');

        if (Schema::hasColumn('time_off_leaves', 'second_approver_id')) {
            $query->leftJoin('employees_employees as leave_approvers', 'leave_approvers.id', '=', DB::raw('COALESCE(time_off_leaves.second_approver_id, time_off_leaves.first_approver_id)'))
                ->addSelect('leave_approvers.name as approver_name');
        }

        return ApprovedLeaveLookup::overlapping($query, $from, $to)
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): array => $rows->all())
            ->all();
    }

    /**
     * The OD marker and the (non-OD) leave portions covering a day.
     *
     * @param  array<int, object>  $leaves
     * @return array{0: ?string, 1: array<int, array{name: string, portion: string, hours?: float, window?: ?array{0: int, 1: int}, approver?: ?string}>}
     */
    public function dayLeave(array $leaves, string $date): array
    {
        $od = null;
        $parts = [];
        $pattern = (string) config('attendance.monthly_report.on_duty_leave_type_pattern', '/\b(on[\s\-_]?duty|o\.?d\.?)\b/i');

        foreach ($leaves as $row) {
            if (! ApprovedLeaveLookup::covers($row, $date)) {
                continue;
            }

            $name = trim((string) ($row->leave_type_name ?? '')) ?: 'Leave';
            $portion = match (true) {
                (bool) ($row->request_unit_half ?? false)  => ($row->request_date_from_period ?? null) === 'afternoon' ? 'second_half' : 'first_half',
                (bool) ($row->request_unit_hours ?? false) => 'hours',
                default                                    => 'full',
            };

            if ($pattern !== '' && @preg_match($pattern, $name) === 1) {
                $od = $od === 'full' || $portion === 'full' || $portion === 'hours' ? 'full' : ($od !== null && $od !== $portion ? 'full' : $portion);

                continue;
            }

            $candidate = ['name' => $name, 'portion' => $portion, 'approver' => ($row->approver_name ?? null) ?: null];
            if ($portion === 'hours') {
                $candidate['hours'] = $this->leaveHours($row);
                $candidate['window'] = $this->leaveWindow($row);
            }

            // A full day beats part days when approved requests overlap.
            if ($portion === 'full') {
                $parts = [$candidate];

                break;
            }

            $parts[] = $candidate;
        }

        return [$od, $parts];
    }

    protected function leaveHours(object $leave): float
    {
        $window = $this->leaveWindow($leave);
        if ($window !== null) {
            return round(($window[1] - $window[0]) / 3600, 4);
        }

        if (($leave->number_of_hours ?? null) !== null && (float) $leave->number_of_hours > 0) {
            return (float) $leave->number_of_hours;
        }

        return 0.0;
    }

    /** @return array{0: int, 1: int}|null  the short leave's from-to in seconds after midnight */
    protected function leaveWindow(object $leave): ?array
    {
        $from = $leave->request_hour_from ?? null;
        $to = $leave->request_hour_to ?? null;

        if ($from === null || $to === null || (float) $to <= (float) $from) {
            return null;
        }

        return [(int) round((float) $from * 3600), (int) round((float) $to * 3600)];
    }

    /** @return Collection<int, CalendarLeave> */
    protected function holidays(int $companyId, string $from, string $to): Collection
    {
        return CalendarLeave::query()
            ->where(fn (Builder $query) => $query->where('time_type', 'leave')->orWhereNull('time_type'))
            ->where(fn (Builder $query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereDate('date_from', '<=', $to)
            ->whereDate('date_to', '>=', $from)
            ->get(['id', 'name', 'date_from', 'date_to', 'calendar_id']);
    }

    /** @param  Collection<int, CalendarLeave>  $holidays */
    protected function holidayOn(Collection $holidays, string $date, ?int $calendarId): ?string
    {
        $holiday = $holidays->first(fn (CalendarLeave $holiday): bool => Carbon::parse($holiday->date_from)->toDateString() <= $date
            && Carbon::parse($holiday->date_to)->toDateString() >= $date
            && ($holiday->calendar_id === null || (int) $holiday->calendar_id === $calendarId));

        return $holiday ? (string) $holiday->name : null;
    }

    protected function isEmployedOn(Employee $employee, string $date): bool
    {
        $joined = $employee->joining_date ? Carbon::parse($employee->joining_date)->toDateString() : null;
        $left = $employee->departure_date ? Carbon::parse($employee->departure_date)->toDateString() : null;

        return ($joined === null || $joined <= $date) && ($left === null || $left >= $date);
    }

    /** @param  array<int, array<string, mixed>>  $parts */
    protected function leaveDays(string $type, bool $isWorking, array $parts, int $requiredMinutes): float
    {
        if ($type === 'leave') {
            return 1.0;
        }

        if (! $isWorking) {
            return 0.0;
        }

        return (float) collect($parts)->sum(fn (array $part): float => match ($part['portion']) {
            'first_half', 'second_half' => 0.5,
            'hours'                     => $requiredMinutes > 0 ? round(((float) ($part['hours'] ?? 0)) * 60 / $requiredMinutes, 2) : 0.0,
            default                     => 0.0,
        });
    }

    /**
     * [pairs, first in, last out, missing check-in] from either intervals or the legacy in / out.
     *
     * @param  array<string, mixed>  $day
     * @return array{0: array<int, array{0: int, 1: ?int}>, 1: ?int, 2: ?int, 3: bool}
     */
    protected function normalisePairs(array $day): array
    {
        if (array_key_exists('intervals', $day)) {
            $pairs = collect($day['intervals'])
                ->filter(fn ($pair): bool => is_array($pair) && $pair[0] !== null)
                ->map(fn (array $pair): array => [(int) $pair[0], $pair[1] !== null ? (int) $pair[1] : null])
                ->sortBy(0)
                ->values()
                ->all();

            $in = $pairs !== [] ? $pairs[0][0] : null;
            $outs = array_filter(array_column($pairs, 1), fn ($value): bool => $value !== null);

            return [$pairs, $in, $outs !== [] ? max($outs) : null, false];
        }

        $in = $day['in'] ?? null;
        $out = $day['out'] ?? null;

        if ($in === null) {
            return [[], null, $out, $out !== null];
        }

        $open = $out === null || ($day['missing_check_out'] ?? false);

        return [[[(int) $in, $open ? null : (int) $out]], $in, $out, false];
    }

    /** @param  array<int, array{0: int, 1: ?int}>  $pairs */
    protected function intervalsText(array $pairs): string
    {
        return implode(', ', array_map(fn (array $pair): string => $this->clock($pair[0]).'–'.($pair[1] !== null ? $this->clock($pair[1]) : '…'), $pairs));
    }

    protected function clock(int $seconds): string
    {
        $seconds = $seconds % 86400;

        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    protected function unionWithin(array $intervals, int $start, int $end): int
    {
        return $this->measure($this->intersect($this->merge($intervals), [[$start, $end]]));
    }

    /**
     * Sorted, non-overlapping union of intervals.
     *
     * @param  array<int, array{0: int, 1: int}>  $intervals
     * @return array<int, array{0: int, 1: int}>
     */
    protected function merge(array $intervals): array
    {
        $sorted = collect($intervals)
            ->filter(fn (array $interval): bool => $interval[1] > $interval[0])
            ->sortBy(0)
            ->values()
            ->all();

        $merged = [];
        foreach ($sorted as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);

                continue;
            }
            $merged[] = [$interval[0], $interval[1]];
        }

        return $merged;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $a
     * @param  array<int, array{0: int, 1: int}>  $b
     * @return array<int, array{0: int, 1: int}>
     */
    protected function intersect(array $a, array $b): array
    {
        $result = [];
        foreach ($this->merge($a) as [$aStart, $aEnd]) {
            foreach ($this->merge($b) as [$bStart, $bEnd]) {
                $from = max($aStart, $bStart);
                $to = min($aEnd, $bEnd);
                if ($to > $from) {
                    $result[] = [$from, $to];
                }
            }
        }

        return $this->merge($result);
    }

    /**
     * $a minus $b.
     *
     * @param  array<int, array{0: int, 1: int}>  $a
     * @param  array<int, array{0: int, 1: int}>  $b
     * @return array<int, array{0: int, 1: int}>
     */
    protected function subtract(array $a, array $b): array
    {
        $result = $this->merge($a);
        foreach ($this->merge($b) as [$cutStart, $cutEnd]) {
            $next = [];
            foreach ($result as [$from, $to]) {
                if ($cutEnd <= $from || $cutStart >= $to) {
                    $next[] = [$from, $to];

                    continue;
                }
                if ($cutStart > $from) {
                    $next[] = [$from, $cutStart];
                }
                if ($cutEnd < $to) {
                    $next[] = [$cutEnd, $to];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /** @param  array<int, array{0: int, 1: int}>  $intervals */
    protected function measure(array $intervals): int
    {
        return (int) array_sum(array_map(fn (array $interval): int => max(0, $interval[1] - $interval[0]), $intervals));
    }

    /** @return array{start: int, end: int, required: int, credit: int, grace: int} */
    protected function settings(): array
    {
        $toSeconds = function (string $time): int {
            [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

            return $hours * 3600 + $minutes * 60;
        };

        return [
            'start'    => $toSeconds((string) config('attendance.monthly_report.office_start', '10:00')),
            'end'      => $toSeconds((string) config('attendance.monthly_report.office_end', '18:00')),
            'required' => (int) config('attendance.monthly_report.daily_required_minutes', 480),
            'credit'   => (int) config('attendance.monthly_report.max_outside_credit_minutes', 60),
            'grace'    => (int) config('attendance.monthly_report.late_grace_minutes', 0),
        ];
    }

    protected function monthStart(string $month): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('The month must look like 2026-08.');
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $this->timezone())->startOfDay();
    }

    /** @throws AuthorizationException */
    protected function authorizeCompany(User $user, int $companyId): void
    {
        if (! static::canView($user)) {
            throw new AuthorizationException('You may not view attendance reports.');
        }

        if (! CurrentCompany::canAccess($companyId, $user)) {
            throw new AuthorizationException('You do not belong to that company.');
        }
    }

    /** @return array{id: int, name: string} */
    protected function companyInfo(int $companyId): array
    {
        return ['id' => $companyId, 'name' => (string) Company::query()->whereKey($companyId)->value('name')];
    }
}
