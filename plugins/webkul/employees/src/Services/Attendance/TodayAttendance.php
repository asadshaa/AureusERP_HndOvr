<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;

/**
 * One company's attendance for one day (Attendance -> Today's attendance,
 * the dashboard roll call and the 11:00 HR digest).
 *
 * Every employee gets one status, classified with the same rules as the
 * monthly report (MonthlyAttendanceReport::calculateDay): office window, late
 * grace, approved leave only (ApprovedLeaveLookup: state validate_two; a
 * pending request never excuses absence), On-Duty leave types, public
 * holidays and weekends. Nothing is written.
 *
 * Several check-in / check-out pairs per day: worked so far = the sum of
 * the day's intervals (plus the running one today), first in = the first
 * interval, last out = the last closed interval, an open interval = still
 * checked in. Late / left early / half day / short leave come from
 * MonthlyAttendanceReport::calculateDay over those intervals.
 *
 * Queries are flat in the number of employees: the visible employee ids,
 * the employees (+ department, line manager), one aggregate over the day's
 * attendance records, one query for the day's intervals, the company
 * policy, the day's approved leave and the day's holidays.
 */
class TodayAttendance
{
    public const ABSENT = 'absent';

    public const MISSING_CHECK_OUT = 'missing_check_out';

    public const LATE = 'late';

    public const HALF_DAY = 'half_day';

    public const SHORT_LEAVE = 'short_leave';

    public const PRESENT = 'present';

    public const ON_DUTY = 'on_duty';

    public const ON_LEAVE = 'on_leave';

    public const HOLIDAY = 'holiday';

    public const WEEKEND = 'weekend';

    /** Problems first, then the rest; the table's default order. */
    public const STATUS_RANK = [
        self::ABSENT            => 0,
        self::MISSING_CHECK_OUT => 1,
        self::LATE              => 2,
        self::HALF_DAY          => 3,
        self::SHORT_LEAVE       => 4,
        self::PRESENT           => 5,
        self::ON_DUTY           => 6,
        self::ON_LEAVE          => 7,
        self::HOLIDAY           => 8,
        self::WEEKEND           => 9,
    ];

    public function __construct(
        protected MonthlyAttendanceReport $report,
    ) {}

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::ABSENT            => 'Absent',
            self::MISSING_CHECK_OUT => 'Missing check-out',
            self::LATE              => 'Late',
            self::HALF_DAY          => 'Half day',
            self::SHORT_LEAVE       => 'Short leave',
            self::PRESENT           => 'Present',
            self::ON_DUTY           => 'On-Duty',
            self::ON_LEAVE          => 'On leave',
            self::HOLIDAY           => 'Holiday',
            self::WEEKEND           => 'Weekend',
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::ABSENT, self::MISSING_CHECK_OUT => 'danger',
            self::LATE, self::HALF_DAY            => 'warning',
            self::SHORT_LEAVE                     => 'info',
            self::PRESENT                         => 'success',
            self::ON_DUTY, self::ON_LEAVE         => 'info',
            default                               => 'gray',
        };
    }

    public function timezone(): string
    {
        return $this->report->timezone();
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    /**
     * The day for one company, limited to the employees the user may see
     * (HR: the whole company; a line manager: their reporting tree).
     *
     * @param  string|null  $date  Y-m-d (local); null = today
     * @return array{company: array{id: int, name: string}, date: string, date_label: string, is_today: bool, off_day: ?array{type: string, name: string}, rows: array<int, array<string, mixed>>, counts: array<string, int>}
     *
     * @throws AuthorizationException
     */
    public function forCompany(User $user, int $companyId, ?string $date = null, ?int $departmentId = null): array
    {
        $date = $this->normalizeDate($date);

        $employees = $this->report->reportableEmployees($user, $companyId, $departmentId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $date))
            ->with(['department:id,name', 'parent:id,name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'company_id', 'name', 'department_id', 'parent_id', 'calendar_id', 'joining_date', 'departure_date', 'employee_number']);

        return $this->build($employees, $companyId, $date);
    }

    /**
     * The same day for every employee of the company, without a user (the
     * scheduled HR digest; callers decide who receives it).
     *
     * @return array<string, mixed>
     */
    public function forWholeCompany(int $companyId, ?string $date = null): array
    {
        $date = $this->normalizeDate($date);

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $date))
            ->get(['id', 'company_id', 'name', 'department_id', 'parent_id', 'calendar_id', 'joining_date', 'departure_date', 'employee_number']);

        return $this->build($employees, $companyId, $date);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    protected function build(Collection $employees, int $companyId, string $date): array
    {
        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);
        $isToday = $date === $now->toDateString();
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $secondsNow = $isToday ? $day->diffInSeconds($now, false) : null;
        $officeEnd = $this->toSeconds((string) config('attendance.monthly_report.office_end', '18:00'));
        $dayOver = $date < $now->toDateString() || ($isToday && $secondsNow >= $officeEnd);

        $weekend = in_array($day->dayOfWeekIso, array_map('intval', (array) config('attendance.monthly_report.weekend_days', [6, 7])), true);
        $holidays = $this->holidays($companyId, $date);
        $companyHoliday = $holidays->first(fn (CalendarLeave $holiday): bool => $holiday->calendar_id === null);

        $employeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $punches = $employeeIds === [] ? [] : $this->punches($companyId, $employeeIds, $date, $day, $isToday ? $now : null);
        $leaves = $employeeIds === [] ? [] : $this->report->leaves($employeeIds, $date, $date);
        $creditCap = $employeeIds === [] ? null : $this->report->policyFor($companyId)['daily_extra_credit_cap_minutes'];

        $rows = [];

        foreach ($employees as $employee) {
            $punch = $punches[$employee->id] ?? null;
            [$od, $parts] = $this->report->dayLeave($leaves[$employee->id] ?? [], $date);
            $leave = $parts[0] ?? null;
            $calendarId = $employee->calendar_id ? (int) $employee->calendar_id : null;
            $holiday = $holidays->first(fn (CalendarLeave $holiday): bool => $holiday->calendar_id === null || (int) $holiday->calendar_id === $calendarId);

            $calculated = $this->report->calculateDay([
                'intervals'          => $punch['intervals'] ?? [],
                'weekend'            => $weekend,
                'holiday'            => $holiday ? (string) $holiday->name : null,
                'employed'           => true,
                'future'             => false,
                'od'                 => $od,
                'leaves'             => $parts,
                'credit_cap_minutes' => $creditCap,
            ]);

            // An open shift today is "still at work" until the office day ends.
            $missingCheckOut = $calculated['missing_check_out'] && $dayOver;

            [$status, $label] = $this->classify($calculated, $parts, $od, $holiday?->name, $missingCheckOut, $isToday, $secondsNow);

            $rows[(int) $employee->id] = [
                'employee_id'       => (int) $employee->id,
                'employee'          => (string) $employee->name,
                'code'              => (string) ($employee->employee_number ?? ''),
                'department_id'     => $employee->department_id ? (int) $employee->department_id : null,
                'department'        => (string) ($employee->department?->name ?? ''),
                'line_manager'      => (string) ($employee->parent?->name ?? ''),
                'status'            => $status,
                'status_label'      => $label,
                'status_rank'       => self::STATUS_RANK[$status],
                'first_in'          => $this->clock($punch['in'] ?? null),
                'last_out'          => $this->clock($punch['out'] ?? null),
                'check_ins'         => (string) $calculated['intervals_text'],
                'interval_count'    => count($calculated['intervals']),
                'currently_in'      => $isToday && (bool) ($punch['open'] ?? false),
                'worked_seconds'    => (int) ($punch['worked'] ?? 0),
                'worked'            => $punch ? $this->hoursMinutes((int) $punch['worked']) : '',
                'source'            => (string) ($punch['source'] ?? ''),
                'remote'            => (bool) ($punch['remote'] ?? false),
                'gps_office'        => (bool) ($punch['gps_office'] ?? false),
                'needs_review'      => (bool) ($punch['needs_review'] ?? false),
                'checked_in'        => ($punch['in'] ?? null) !== null,
                'late'              => (bool) $calculated['late'],
                'late_minutes'      => (int) $calculated['late_minutes'],
                // Leaving early / time away are only final once the office day is over.
                'left_early_minutes'=> $dayOver ? (int) $calculated['left_early_minutes'] : 0,
                'away_minutes'      => $dayOver ? (int) $calculated['gap_minutes'] : 0,
                'extra_minutes'     => (int) $calculated['extra_minutes'],
                'absent'            => (bool) $calculated['absent'],
                'missing_check_out' => $missingCheckOut,
                'on_leave'          => $leave !== null,
                'leave_name'        => $leave['name'] ?? null,
                'short_leave'       => collect($parts)->contains(fn (array $part): bool => $part['portion'] === 'hours'),
                'on_duty'           => $od !== null,
                'remarks'           => (string) $calculated['remarks'],
            ];
        }

        return [
            'company'    => ['id' => $companyId, 'name' => (string) Company::query()->whereKey($companyId)->value('name')],
            'date'       => $date,
            'date_label' => $day->format('l, d M Y'),
            'is_today'   => $isToday,
            'off_day'    => $companyHoliday
                ? ['type' => self::HOLIDAY, 'name' => (string) $companyHoliday->name]
                : ($weekend ? ['type' => self::WEEKEND, 'name' => $day->format('l')] : null),
            'rows'       => $rows,
            'counts'     => $this->counts($rows),
        ];
    }

    /**
     * Rows in the table's default order: problems first, then by name.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function sortProblemsFirst(array $rows): array
    {
        uasort($rows, fn (array $a, array $b): int => [$a['status_rank'], mb_strtolower($a['employee']), $a['employee_id']]
            <=> [$b['status_rank'], mb_strtolower($b['employee']), $b['employee_id']]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $calculated
     * @param  array<int, array{name: string, portion: string, hours?: float, window?: ?array{0: int, 1: int}}>  $parts  approved part-day / full-day leave
     * @return array{0: string, 1: string}
     */
    protected function classify(array $calculated, array $parts, ?string $od, ?string $holidayName, bool $missingCheckOut, bool $isToday, ?int $secondsNow): array
    {
        $worked = $calculated['first_in'] !== null ? ' (worked)' : '';
        $leave = $parts[0] ?? null;
        $halfDay = collect($parts)->first(fn (array $part): bool => in_array($part['portion'], ['first_half', 'second_half'], true));
        $shortLeave = collect($parts)->first(fn (array $part): bool => $part['portion'] === 'hours');

        return match (true) {
            $calculated['type'] === 'weekend' => [self::WEEKEND, 'Weekend'.$worked],
            $calculated['type'] === 'holiday' => [self::HOLIDAY, 'Holiday'.($holidayName ? ': '.$holidayName : '').$worked],
            $calculated['type'] === 'leave'   => [self::ON_LEAVE, 'On leave: '.$leave['name']],
            $calculated['absent']             => [self::ABSENT, $this->absentLabel($leave, $od, $isToday, $secondsNow)],
            $missingCheckOut                  => [self::MISSING_CHECK_OUT, 'Missing check-out'],
            $calculated['late']               => [self::LATE, 'Late '.$this->lateLabel((int) $calculated['late_minutes'])],
            $od === 'full'                    => [self::ON_DUTY, 'On-Duty'],
            $od !== null                      => [self::HALF_DAY, 'Half day: On-Duty'],
            $halfDay !== null                 => [self::HALF_DAY, 'Half day: '.$halfDay['name']],
            $shortLeave !== null              => [self::SHORT_LEAVE, 'Short leave'.(isset($shortLeave['window']) && $shortLeave['window'] !== null ? ' '.$this->clock($shortLeave['window'][0]).'–'.$this->clock($shortLeave['window'][1]) : '')],
            $leave !== null                   => [self::HALF_DAY, 'Half day: '.$leave['name']],
            default                           => [self::PRESENT, 'Present'],
        };
    }

    /** @param  array{name: string, portion: string, hours?: float}|null  $leave */
    protected function absentLabel(?array $leave, ?string $od, bool $isToday, ?int $secondsNow): string
    {
        $label = 'Absent';

        if ($isToday && $secondsNow !== null) {
            $threshold = $this->toSeconds((string) config('attendance.monthly_report.office_start', '10:00'))
                + (int) config('attendance.monthly_report.late_grace_minutes', 0) * 60;

            if ($secondsNow <= $threshold) {
                $label = 'Not checked in yet';
            }
        }

        if ($leave !== null) {
            return $label.' (other half: '.$leave['name'].')';
        }

        return $od !== null ? $label.' (other half: On-Duty)' : $label;
    }

    protected function lateLabel(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function counts(array $rows): array
    {
        $rows = collect($rows);

        return [
            'total'             => $rows->count(),
            'present'           => $rows->where('checked_in', true)->count(),
            'late'              => $rows->where('late', true)->count(),
            'absent'            => $rows->where('status', self::ABSENT)->count(),
            'on_leave'          => $rows->where('on_leave', true)->count(),
            'short_leave'       => $rows->where('short_leave', true)->count(),
            'on_duty'           => $rows->where('on_duty', true)->count(),
            'remote'            => $rows->where('remote', true)->count(),
            'gps_office'        => $rows->where('gps_office', true)->count(),
            'missing_check_out' => $rows->where('missing_check_out', true)->count(),
            'needs_review'      => $rows->where('needs_review', true)->count(),
        ];
    }

    /**
     * One aggregate row per employee for the day: earliest check-in, latest
     * check-out, the latest still-open check-in, closed hours, sources and
     * verification flags. Times are seconds after the local midnight, to the
     * minute (as the monthly report counts them).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{in: ?int, out: ?int, open: bool, worked: int, source: string, remote: bool, gps_office: bool, needs_review: bool}>
     */
    protected function punches(int $companyId, array $employeeIds, string $date, CarbonImmutable $midnight, ?CarbonImmutable $now): array
    {
        $intervals = $this->intervals($companyId, $employeeIds, $date, $midnight);

        $needsReview = AttendanceVerificationStatus::NeedsReview->value;
        $remote = AttendanceVerificationStatus::Remote->value;
        $gps = AttendanceSource::Gps->value;
        $selfService = AttendanceSource::SelfService->value;

        return AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', $date)
            ->groupBy('employee_id')
            ->selectRaw('employee_id')
            ->selectRaw('MIN(check_in) as first_in')
            ->selectRaw('MAX(check_out) as last_out')
            ->selectRaw('MAX(CASE WHEN check_out IS NULL THEN check_in END) as open_in')
            ->selectRaw('SUM(CASE WHEN check_in IS NOT NULL AND check_out IS NOT NULL THEN worked_hours ELSE 0 END) as closed_hours')
            ->selectRaw('MIN(source) as min_source')
            ->selectRaw('MAX(source) as max_source')
            ->selectRaw('SUM(CASE WHEN verification_status = ? THEN 1 ELSE 0 END) as needs_review', [$needsReview])
            ->selectRaw('SUM(CASE WHEN verification_status = ? OR source = ? THEN 1 ELSE 0 END) as remote_count', [$remote, $selfService])
            ->selectRaw('SUM(CASE WHEN source = ? AND (verification_status IS NULL OR verification_status <> ?) THEN 1 ELSE 0 END) as gps_count', [$gps, $remote])
            ->toBase()
            ->get()
            ->mapWithKeys(function (object $row) use ($midnight, $now, $intervals): array {
                $seconds = fn ($moment): ?int => $moment
                    ? (int) floor($midnight->diffInSeconds(CarbonImmutable::parse($moment, 'UTC'), false) / 60) * 60
                    : null;

                $in = $seconds($row->first_in);
                $out = $seconds($row->last_out);
                $openIn = $seconds($row->open_in);
                $open = $openIn !== null && ($out === null || $openIn > $out);
                $worked = (int) round((float) $row->closed_hours * 3600);

                if ($open && $now !== null) {
                    $worked += max(0, (int) CarbonImmutable::parse($row->open_in, 'UTC')->diffInSeconds($now, false));
                }

                $source = $row->min_source === $row->max_source ? (string) $row->min_source : 'mixed';
                $pairs = $intervals[(int) $row->employee_id] ?? null;

                if ($pairs !== null) {
                    // Several pairs: first in, last closed out, worked = sum (+ the running pair today).
                    $in = $pairs['pairs'][0][0];
                    $closedOuts = array_filter(array_column($pairs['pairs'], 1), fn ($value): bool => $value !== null);
                    $out = $closedOuts !== [] ? max($closedOuts) : null;
                    $open = $pairs['open_at'] !== null;
                    $worked = $pairs['closed_seconds'];
                    if ($open && $now !== null) {
                        $worked += max(0, (int) $pairs['open_at']->diffInSeconds($now, false));
                    }
                } else {
                    $pairs = ['pairs' => $in !== null ? [[$in, $open ? null : $out]] : []];
                }

                return [(int) $row->employee_id => [
                    'in'           => $in,
                    'out'          => $out,
                    'open'         => $open,
                    'intervals'    => $pairs['pairs'],
                    'worked'       => $worked,
                    'source'       => $source,
                    'remote'       => (int) $row->remote_count > 0,
                    'gps_office'   => (int) $row->gps_count > 0,
                    'needs_review' => (int) $row->needs_review > 0,
                ]];
            })
            ->all();
    }

    /**
     * The day's active check-in / check-out intervals per employee, in one
     * query (seconds after local midnight, to the minute).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{pairs: array<int, array{0: int, 1: ?int}>, closed_seconds: int, open_at: ?CarbonImmutable}>
     */
    protected function intervals(int $companyId, array $employeeIds, string $date, CarbonImmutable $midnight): array
    {
        if (! AttendanceIntervalService::available()) {
            return [];
        }

        $seconds = fn ($moment): ?int => $moment
            ? (int) floor($midnight->diffInSeconds(CarbonImmutable::parse($moment, 'UTC'), false) / 60) * 60
            : null;

        $result = [];

        AttendanceInterval::query()
            ->join('employees_attendance_records', 'employees_attendance_records.id', '=', 'employees_attendance_intervals.attendance_record_id')
            ->where('employees_attendance_records.company_id', $companyId)
            ->whereIn('employees_attendance_records.employee_id', $employeeIds)
            ->whereDate('employees_attendance_records.attendance_date', $date)
            ->whereNull('employees_attendance_intervals.voided_at')
            ->orderBy('employees_attendance_intervals.check_in')
            ->orderBy('employees_attendance_intervals.id')
            ->toBase()
            ->get(['employees_attendance_intervals.employee_id', 'employees_attendance_intervals.check_in', 'employees_attendance_intervals.check_out'])
            ->each(function (object $interval) use (&$result, $seconds): void {
                $employeeId = (int) $interval->employee_id;
                $result[$employeeId] ??= ['pairs' => [], 'closed_seconds' => 0, 'open_at' => null];
                $in = $seconds($interval->check_in);
                $out = $seconds($interval->check_out);
                $result[$employeeId]['pairs'][] = [$in, $out];

                if ($out === null) {
                    $result[$employeeId]['open_at'] = CarbonImmutable::parse($interval->check_in, 'UTC');
                } else {
                    $result[$employeeId]['closed_seconds'] += max(0, $out - $in);
                }
            });

        return $result;
    }

    /** @return Collection<int, CalendarLeave> */
    protected function holidays(int $companyId, string $date): Collection
    {
        return CalendarLeave::query()
            ->where(fn (Builder $query) => $query->where('time_type', 'leave')->orWhereNull('time_type'))
            ->where(fn (Builder $query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date)
            ->get(['id', 'name', 'date_from', 'date_to', 'calendar_id']);
    }

    protected function normalizeDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return $this->today();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('The date must look like 2026-10-28.');
        }

        return Carbon::createFromFormat('Y-m-d', $date, $this->timezone())->toDateString();
    }

    protected function clock(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $prefix = $seconds >= 86400 ? '+1 ' : ($seconds < 0 ? '-1 ' : '');
        $seconds = (($seconds % 86400) + 86400) % 86400;

        return $prefix.sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    protected function hoursMinutes(int $seconds): string
    {
        return intdiv($seconds, 3600).':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    }

    protected function toSeconds(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 3600 + $minutes * 60;
    }
}
