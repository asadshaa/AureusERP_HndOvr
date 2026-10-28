<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;

/**
 * Monthly attendance report, exactly as HR's Google Sheet template computes it.
 *
 * Per working day: the part of the day inside the office window
 * (config attendance.monthly_report.office_start / office_end, 10:00-18:00)
 * counts in full; time before the start or after the end counts up to
 * max_outside_credit_minutes (60). Final = inside + credit (not capped at 8h).
 * Month: worked = sum(final); required = working days x daily_required_minutes;
 * delta = max(0, required - worked); penalty = delta / required.
 *
 * Inputs are read, never written: punches from employees_attendance_records
 * (the day's earliest check-in and latest check-out), approved leave from the
 * Time Off plugin (state validate_two, via ApprovedLeaveLookup), public
 * holidays from calendar_leaves. An approved Time Off whose leave type name
 * matches on_duty_leave_type_pattern is On-Duty (OD): the whole day (or, for
 * a half-day request, the morning / afternoon half) counts as worked.
 *
 * Every figure is kept in seconds internally, so the Excel durations match
 * what the sheet shows; minutes columns are whole (truncated) minutes.
 */
class MonthlyAttendanceReport
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
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
     * @return array{company: array{id: int, name: string}, month: string, month_label: string, generated_at: CarbonImmutable, generated_by: ?string, employees: array<int, array<string, mixed>>}
     *
     * @throws AuthorizationException
     */
    public function forCompany(User $user, int $companyId, string $month, ?int $departmentId = null, array $employeeIds = []): array
    {
        $monthStart = $this->monthStart($month);

        $employees = $this->reportableEmployees($user, $companyId, $departmentId)
            ->when($employeeIds !== [], fn (Builder $query) => $query->whereIn('id', array_map('intval', $employeeIds)))
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $monthStart->toDateString()))
            ->where(fn (Builder $query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $monthStart->endOfMonth()->toDateString()))
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNotNull('departure_date'))
            ->with(['department', 'job'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return [
            'company'      => $this->companyInfo($companyId),
            'month'        => $monthStart->format('Y-m'),
            'month_label'  => $monthStart->format('F Y'),
            'generated_at' => CarbonImmutable::now($this->timezone()),
            'generated_by' => $user->name,
            'employees'    => $this->build($employees, $companyId, $monthStart),
        ];
    }

    /**
     * One employee's month (no user authorisation: callers authorise). An
     * employee of another company is refused.
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

        return $this->build(collect([$employee->loadMissing(['department', 'job'])]), $companyId, $this->monthStart($month))[0];
    }

    /**
     * The template's arithmetic for one day. Times are seconds after the
     * day's local midnight (a check-out after midnight is > 86400).
     *
     * od is null | full | first_half | second_half; a leave portion is full | first_half | second_half | hours.
     *
     * @param  array{in?: ?int, out?: ?int, missing_check_out?: bool, weekend?: bool, holiday?: ?string, employed?: bool, future?: bool, od?: ?string, leave?: ?array{name: string, portion: string, hours?: float}}  $day
     * @return array<string, mixed>
     */
    public function calculateDay(array $day): array
    {
        $settings = $this->settings();
        $start = $settings['start'];
        $end = $settings['end'];
        $middle = $start + intdiv($end - $start, 2);
        $required = $settings['required'] * 60;

        $in = $day['in'] ?? null;
        $out = $day['out'] ?? null;
        $od = $day['od'] ?? null;
        $leave = $day['leave'] ?? null;
        $remarks = [];

        $type = 'working';
        if (! ($day['employed'] ?? true)) {
            $type = 'not_employed';
            $remarks[] = 'Not employed';
        } elseif ($day['future'] ?? false) {
            $type = 'future';
        } elseif ($day['weekend'] ?? false) {
            $type = 'weekend';
            $remarks[] = 'Weekend';
        } elseif (($day['holiday'] ?? null) !== null) {
            $type = 'holiday';
            $remarks[] = 'Public holiday'.($day['holiday'] !== '' ? ': '.$day['holiday'] : '');
        } elseif ($leave !== null && $leave['portion'] === 'full') {
            $type = 'leave';
            $remarks[] = $leave['name'];
        }

        $isWorking = $type === 'working';
        $excusedSeconds = 0;

        if ($isWorking && $leave !== null) {
            $excusedSeconds = match ($leave['portion']) {
                'first_half', 'second_half' => intdiv($required, 2),
                'hours'                     => (int) round(((float) ($leave['hours'] ?? 0)) * 3600),
                default                     => 0,
            };
            $remarks[] = $leave['name'].match ($leave['portion']) {
                'first_half'  => ' - first half',
                'second_half' => ' - second half',
                'hours'       => ' ('.rtrim(rtrim(number_format((float) ($leave['hours'] ?? 0), 2), '0'), '.').'h)',
                default       => '',
            };
        }

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

        $missingCheckOut = $in !== null && ($out === null || ($day['missing_check_out'] ?? false));
        $missingCheckIn = $in === null && $out !== null;
        $hasPair = $in !== null && $out !== null && ! $missingCheckOut && $out > $in;

        $intervals = [];
        if ($hasPair) {
            $intervals[] = [$in, $out];
        }
        if ($odInterval !== null) {
            $intervals[] = $odInterval;
        }

        $inside = $this->unionWithin($intervals, $start, $end);
        $before = $hasPair ? max(0, min($out, $start) - $in) : 0;
        $after = $hasPair ? max(0, $out - max($in, $end)) : 0;
        $credit = min($settings['credit'] * 60, $before + $after);
        $final = $inside + $credit;

        $clampedIn = $in !== null ? max($in, $start) : null;
        $clampedOut = $hasPair ? min($out, $end) : null;
        if ($odInterval !== null) {
            $clampedIn = $clampedIn === null ? $odInterval[0] : min($clampedIn, $odInterval[0]);
            $clampedOut = $clampedOut === null ? $odInterval[1] : max($clampedOut, $odInterval[1]);
        }

        $requiredSeconds = $isWorking ? max(0, $required - $excusedSeconds) : 0;
        $hasPunch = $in !== null || $out !== null;

        $absent = $isWorking && $requiredSeconds > 0 && ! $hasPunch && $odInterval === null;
        if ($absent) {
            $remarks[] = 'Absent';
        }
        if ($missingCheckOut) {
            $remarks[] = 'Missing check-out';
        }
        if ($missingCheckIn) {
            $remarks[] = 'Missing check-in';
        }

        $firstHalfExcused = $od === 'first_half' || ($leave['portion'] ?? null) === 'first_half';
        $lateThreshold = ($firstHalfExcused ? $middle : $start) + $settings['grace'] * 60;
        $late = $isWorking && $in !== null && $od !== 'full' && $in > $lateThreshold;
        $lateMinutes = $late ? intdiv($in - ($firstHalfExcused ? $middle : $start), 60) : 0;
        if ($late) {
            $remarks[] = 'Late';
        }

        $offDayWork = in_array($type, ['weekend', 'holiday', 'leave'], true) && $hasPair;
        if ($offDayWork) {
            $remarks[] = 'Worked';
        }

        return [
            'type'               => $type,
            'working'            => $isWorking,
            'first_in'           => $in,
            'last_out'           => $out,
            'remarks'            => implode('; ', array_unique($remarks)),
            'clamped_in'         => ($isWorking || $hasPair) ? $clampedIn : null,
            'clamped_out'        => ($isWorking || $hasPair) ? $clampedOut : null,
            'before_minutes'     => intdiv($before, 60),
            'after_minutes'      => intdiv($after, 60),
            'inside_seconds'     => $inside,
            'credit_minutes'     => intdiv($credit, 60),
            'credit_seconds'     => $credit,
            'final_seconds'      => $final,
            'required_seconds'   => $requiredSeconds,
            'present'            => $isWorking && ($hasPunch || $odInterval !== null),
            'absent'             => $absent,
            'late'               => $late,
            'late_minutes'       => $lateMinutes,
            'missing_check_out'  => $isWorking && $missingCheckOut,
            'od'                 => $odInterval !== null ? $od : null,
            'od_days'            => $odInterval === null ? 0.0 : ($od === 'full' ? 1.0 : 0.5),
            'leave_days'         => $this->leaveDays($type, $isWorking, $leave, $settings['required']),
            'off_day_work'       => $offDayWork,
            'off_day_seconds'    => $offDayWork ? $final : 0,
        ];
    }

    /**
     * Month totals of calculated rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarize(array $rows): array
    {
        $countOffDayWork = (bool) config('attendance.monthly_report.count_off_day_work', false);
        $rows = collect($rows);
        $working = $rows->where('working', true);

        $workedSeconds = (int) $working->sum('final_seconds') + ($countOffDayWork ? (int) $rows->sum('off_day_seconds') : 0);
        $requiredSeconds = (int) $working->sum('required_seconds');
        $extraSeconds = (int) $working->sum(fn (array $row): int => max(0, $row['final_seconds'] - $row['required_seconds']))
            + ($countOffDayWork ? 0 : (int) $rows->sum('off_day_seconds'));

        $workedHours = round($workedSeconds / 3600, 2);
        $requiredHours = round($requiredSeconds / 3600, 2);
        $delta = round(max(0, $requiredHours - $workedHours), 2);

        return [
            'working_days'        => $working->where('required_seconds', '>', 0)->count(),
            'present_days'        => $working->where('present', true)->count(),
            'absent_days'         => $working->where('absent', true)->count(),
            'leave_days'          => round((float) $rows->sum('leave_days'), 2),
            'od_days'             => round((float) $rows->sum('od_days'), 2),
            'holidays'            => $rows->where('type', 'holiday')->count(),
            'late_days'           => $working->where('late', true)->count(),
            'late_minutes'        => (int) $working->sum('late_minutes'),
            'missing_check_outs'  => $working->where('missing_check_out', true)->count(),
            'worked_seconds'      => $workedSeconds,
            'required_seconds'    => $requiredSeconds,
            'worked_hours'        => $workedHours,
            'required_hours'      => $requiredHours,
            'delta_hours'         => $delta,
            'penalty_percent'     => $requiredHours > 0 ? round($delta / $requiredHours * 100, 2) : 0.0,
            'extra_minutes'       => intdiv($extraSeconds, 60),
            'off_day_work_hours'  => round((int) $rows->sum('off_day_seconds') / 3600, 2),
        ];
    }

    /**
     * Template column headers, worded from the configured office window
     * (at the defaults this is the sheet's exact wording).
     *
     * @return array<int, string>
     */
    public function dailyHeaders(): array
    {
        $settings = $this->settings();
        $start = $settings['start'];
        $end = $settings['end'];
        $credit = $settings['credit'];

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

    public function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{employee: array<string, mixed>, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}>
     */
    protected function build(Collection $employees, int $companyId, CarbonImmutable $monthStart): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $timezone = $this->timezone();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $from = $monthStart->toDateString();
        $to = $monthEnd->toDateString();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $employeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $punches = $this->punches($companyId, $employeeIds, $from, $to, $timezone);
        $leaves = $this->leaves($employeeIds, $from, $to);
        $holidays = $this->holidays($companyId, $from, $to);
        $weekendDays = array_map('intval', (array) config('attendance.monthly_report.weekend_days', [6, 7]));
        $showOffDays = (bool) config('attendance.monthly_report.show_off_days', false);

        $reports = [];

        foreach ($employees as $employee) {
            $rows = [];

            for ($date = $monthStart; $date->lte($monthEnd); $date = $date->addDay()) {
                $key = $date->toDateString();
                $punch = $punches[$employee->id][$key] ?? null;
                [$od, $leave] = $this->dayLeave($leaves[$employee->id] ?? [], $key);

                $row = $this->calculateDay([
                    'in'                => $punch['in'] ?? null,
                    'out'               => $punch['out'] ?? null,
                    'missing_check_out' => $punch['missing_check_out'] ?? false,
                    'weekend'           => in_array($date->dayOfWeekIso, $weekendDays, true),
                    'holiday'           => $this->holidayOn($holidays, $key, $employee->calendar_id ? (int) $employee->calendar_id : null),
                    'employed'          => $this->isEmployedOn($employee, $key),
                    'future'            => $key > $today,
                    'od'                => $od,
                    'leave'             => $leave,
                ]);

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

            $reports[] = [
                'employee' => [
                    'id'          => (int) $employee->id,
                    'code'        => (string) ($employee->employee_number ?: $employee->barcode ?: ''),
                    'name'        => (string) $employee->name,
                    'department'  => (string) ($employee->department?->name ?? ''),
                    'designation' => (string) ($employee->job_title ?: $employee->job?->name ?: ''),
                ],
                'rows'    => $rows,
                'summary' => $this->summarize($rows),
            ];
        }

        return $reports;
    }

    /**
     * Earliest check-in / latest check-out per employee per local day.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, array{in: ?int, out: ?int, missing_check_out: bool}>>
     */
    protected function punches(int $companyId, array $employeeIds, string $from, string $to, string $timezone): array
    {
        $result = [];

        AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->orderBy('check_in')
            ->get(['employee_id', 'attendance_date', 'check_in', 'check_out'])
            ->each(function (AttendanceRecord $record) use (&$result, $timezone): void {
                $date = $record->attendance_date->toDateString();
                $midnight = CarbonImmutable::parse($date, $timezone)->startOfDay();
                $seconds = fn ($moment): ?int => $moment ? (int) $midnight->diffInSeconds(CarbonImmutable::parse($moment)->setTimezone($timezone), false) : null;

                $in = $seconds($record->check_in);
                $out = $seconds($record->check_out);
                $day = $result[$record->employee_id][$date] ?? ['in' => null, 'out' => null, 'missing_check_out' => false, 'open_in' => null];

                if ($in !== null) {
                    $day['in'] = $day['in'] === null ? $in : min($day['in'], $in);
                }
                if ($out !== null) {
                    $day['out'] = $day['out'] === null ? $out : max($day['out'], $out);
                }
                if ($in !== null && $out === null) {
                    $day['open_in'] = max($day['open_in'] ?? $in, $in);
                }

                // An open shift that started after the last check-out was never closed.
                $day['missing_check_out'] = $day['open_in'] !== null && ($day['out'] === null || $day['open_in'] > $day['out']);
                $result[$record->employee_id][$date] = $day;
            });

        return $result;
    }

    /**
     * Approved Time Off (with its leave type name) overlapping the month, per employee.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, object>>
     */
    protected function leaves(array $employeeIds, string $from, string $to): array
    {
        if (! ApprovedLeaveLookup::available()) {
            return [];
        }

        return ApprovedLeaveLookup::overlapping(
            DB::table('time_off_leaves')
                ->leftJoin('time_off_leave_types', 'time_off_leave_types.id', '=', 'time_off_leaves.holiday_status_id')
                ->whereIn('time_off_leaves.employee_id', $employeeIds)
                ->where('time_off_leaves.state', ApprovedLeaveLookup::APPROVED_STATE)
                ->select('time_off_leaves.*', 'time_off_leave_types.name as leave_type_name'),
            $from,
            $to,
        )
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): array => $rows->all())
            ->all();
    }

    /**
     * The OD marker and the (non-OD) leave covering a day.
     *
     * @param  array<int, object>  $leaves
     * @return array{0: ?string, 1: ?array{name: string, portion: string, hours?: float}}
     */
    protected function dayLeave(array $leaves, string $date): array
    {
        $od = null;
        $leave = null;
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

            $candidate = ['name' => $name, 'portion' => $portion];
            if ($portion === 'hours') {
                $candidate['hours'] = $this->leaveHours($row);
            }

            // A full day beats a part day when two approved requests overlap.
            if ($leave === null || $portion === 'full') {
                $leave = $candidate;
            }
        }

        return [$od, $leave];
    }

    protected function leaveHours(object $leave): float
    {
        if (($leave->number_of_hours ?? null) !== null && (float) $leave->number_of_hours > 0) {
            return (float) $leave->number_of_hours;
        }

        if (($leave->request_hour_from ?? null) !== null && ($leave->request_hour_to ?? null) !== null) {
            return max(0.0, (float) $leave->request_hour_to - (float) $leave->request_hour_from);
        }

        return 0.0;
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

    /** @param  array{name: string, portion: string, hours?: float}|null  $leave */
    protected function leaveDays(string $type, bool $isWorking, ?array $leave, int $requiredMinutes): float
    {
        if ($type === 'leave') {
            return 1.0;
        }

        if (! $isWorking || $leave === null) {
            return 0.0;
        }

        return match ($leave['portion']) {
            'first_half', 'second_half' => 0.5,
            'hours'                     => $requiredMinutes > 0 ? round(((float) ($leave['hours'] ?? 0)) * 60 / $requiredMinutes, 2) : 0.0,
            default                     => 0.0,
        };
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    protected function unionWithin(array $intervals, int $start, int $end): int
    {
        $clipped = collect($intervals)
            ->map(fn (array $interval): array => [max($interval[0], $start), min($interval[1], $end)])
            ->filter(fn (array $interval): bool => $interval[1] > $interval[0])
            ->sortBy(0)
            ->values();

        $total = 0;
        $current = null;

        foreach ($clipped as $interval) {
            if ($current === null) {
                $current = $interval;

                continue;
            }

            if ($interval[0] <= $current[1]) {
                $current[1] = max($current[1], $interval[1]);

                continue;
            }

            $total += $current[1] - $current[0];
            $current = $interval;
        }

        return $total + ($current !== null ? $current[1] - $current[0] : 0);
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
