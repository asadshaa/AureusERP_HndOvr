<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\ShiftOverride;
use Webkul\Employee\Models\ShiftRosterAssignment;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\Data\DaySchedule;
use Webkul\Employee\Services\Attendance\Data\ScheduleSet;

/**
 * Timezone, attendance-date and scheduled-window resolution. Reuses the
 * existing Employee.time_zone -> Calendar.timezone -> app timezone chain
 * (Company has no timezone column) and Calendar::getAttendanceIntervalsBatch()
 * for the day's working window; nothing is invented here.
 *
 * The schedule of an employee on a date (HR parity plan 2.2, 3.4), first
 * match wins:
 *  1. a day override (ShiftOverride: a changed shift, a day off, a swap);
 *  2. a rotating roster (ShiftRosterAssignment): the employee's own, else
 *     their department's;
 *  3. the employee's effective-dated WorkSchedule covering the date;
 *  4. the company default -- the office hours of config/attendance.php
 *     (monthly_report.office_start / office_end, daily_required_minutes,
 *     weekend_days).
 * The monthly report, Today's attendance, the late check-in notice and
 * scheduledWindowFor() all resolve the day through here.
 *
 * Overnight shifts (end before start, e.g. 22:00-06:00) belong to the date
 * they start. A punch after midnight belongs to the previous day's shift
 * while it is before that shift's end plus
 * attendance.shifts.overnight_tolerance_minutes (default 4 h), unless the
 * day's own shift has already started (or is nearer): attributeLocal().
 */
class AttendanceScheduleResolver
{
    private static ?bool $schedulesTableExists = null;

    private static ?bool $shiftTablesExist = null;

    public static function schedulesAvailable(): bool
    {
        return self::$schedulesTableExists ??= Schema::hasTable('employees_work_schedules');
    }

    public static function shiftsAvailable(): bool
    {
        return self::$shiftTablesExist ??= Schema::hasTable('employees_shift_overrides')
            && Schema::hasTable('employees_shift_roster_assignments');
    }

    /** The company default: config office hours, required minutes and weekend days. */
    public function defaultSchedule(): DaySchedule
    {
        $weekend = array_map('intval', (array) config('attendance.monthly_report.weekend_days', [6, 7]));

        return new DaySchedule(
            startSeconds: $this->toSeconds((string) config('attendance.monthly_report.office_start', '10:00')),
            endSeconds: $this->toSeconds((string) config('attendance.monthly_report.office_end', '18:00')),
            requiredMinutes: (int) config('attendance.monthly_report.daily_required_minutes', 480),
            workingDays: array_values(array_diff([1, 2, 3, 4, 5, 6, 7], $weekend)),
        );
    }

    /** The schedule of one employee on one local date (Y-m-d). */
    public function scheduleFor(Employee|int $employee, string $date): DaySchedule
    {
        $employeeId = $employee instanceof Employee ? (int) $employee->id : $employee;

        return $this->pick($this->setsFor([$employee], $date, $date)[$employeeId] ?? new ScheduleSet, $date);
    }

    /**
     * Every schedule period of these employees overlapping [$from, $to], in one query.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, WorkSchedule>> keyed by employee id, in effective_from order
     */
    public function periodsFor(array $employeeIds, string $from, string $to): array
    {
        if ($employeeIds === [] || ! static::schedulesAvailable()) {
            return [];
        }

        return WorkSchedule::query()
            ->whereIn('employee_id', array_map('intval', $employeeIds))
            ->covering($from, $to)
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($periods): array => $periods->all())
            ->all();
    }

    /**
     * Everything that decides these employees' schedules over [$from, $to]:
     * work schedules, roster assignments (own and department), day overrides
     * and the shifts they use. A few queries whatever the number of
     * employees; pick() then needs none.
     *
     * Employees given as models use their department_id; plain ids have
     * their department looked up (one query).
     *
     * @param  iterable<int, Employee|int>  $employees
     * @return array<int, ScheduleSet> keyed by employee id (every employee given)
     */
    public function setsFor(iterable $employees, string $from, string $to): array
    {
        $departments = [];
        $companies = [];
        $ids = [];
        foreach ($employees as $employee) {
            // A model loaded without its company / department is looked up like a plain id.
            if ($employee instanceof Employee && array_key_exists('department_id', $employee->getAttributes()) && array_key_exists('company_id', $employee->getAttributes())) {
                $ids[] = (int) $employee->id;
                $departments[(int) $employee->id] = $employee->department_id ? (int) $employee->department_id : null;
                $companies[(int) $employee->id] = (int) $employee->company_id;
            } else {
                $ids[] = $employee instanceof Employee ? (int) $employee->id : (int) $employee;
            }
        }

        if ($ids === []) {
            return [];
        }

        $periods = $this->periodsFor($ids, $from, $to);

        if (! static::shiftsAvailable()) {
            return array_combine($ids, array_map(fn (int $id): ScheduleSet => new ScheduleSet($periods[$id] ?? []), $ids));
        }

        $missing = array_values(array_diff($ids, array_keys($companies)));
        if ($missing !== []) {
            Employee::query()->withTrashed()->whereIn('id', $missing)->get(['id', 'company_id', 'department_id'])
                ->each(function (Employee $employee) use (&$departments, &$companies): void {
                    $departments[(int) $employee->id] = $employee->department_id ? (int) $employee->department_id : null;
                    $companies[(int) $employee->id] = (int) $employee->company_id;
                });
        }

        // Only employees of companies that use rosters at all need the roster queries.
        $rosteredCompanies = RosterPresence::companiesWithRosters(array_values($companies));
        $rosteredIds = array_values(array_filter($ids, fn (int $id): bool => in_array($companies[$id] ?? 0, $rosteredCompanies, true)));

        if ($rosteredIds === []) {
            return array_combine($ids, array_map(fn (int $id): ScheduleSet => new ScheduleSet($periods[$id] ?? []), $ids));
        }

        $departmentIds = array_values(array_unique(array_filter(array_map(fn (int $id): ?int => $departments[$id] ?? null, $rosteredIds))));

        $assignments = ShiftRosterAssignment::query()
            ->covering($from, $to)
            ->where(fn ($query) => $query->whereIn('employee_id', $rosteredIds)
                ->when($departmentIds !== [], fn ($inner) => $inner->orWhereIn('department_id', $departmentIds)))
            ->with('roster')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $overrides = ShiftOverride::query()
            ->whereIn('employee_id', $rosteredIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();

        $shiftIds = $overrides->pluck('shift_id')->filter()->map(fn ($id): int => (int) $id)
            ->merge($assignments->flatMap(fn (ShiftRosterAssignment $assignment): array => $assignment->roster?->shiftIds() ?? []))
            ->unique()
            ->values()
            ->all();

        $shifts = $shiftIds === [] ? [] : Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id')->all();

        $sets = [];
        foreach ($ids as $id) {
            $own = $assignments->filter(fn (ShiftRosterAssignment $assignment): bool => (int) $assignment->employee_id === $id);
            $department = $departments[$id] ?? null;
            $byDepartment = $department === null ? collect() : $assignments->filter(fn (ShiftRosterAssignment $assignment): bool => $assignment->employee_id === null
                && (int) $assignment->department_id === $department
                && (int) $assignment->company_id === (int) ($companies[$id] ?? 0));

            $sets[$id] = new ScheduleSet(
                periods: $periods[$id] ?? [],
                assignments: [...$own->values()->all(), ...$byDepartment->values()->all()],
                overrides: $overrides->where('employee_id', $id)->keyBy(fn (ShiftOverride $override): string => $override->date->toDateString())->all(),
                shifts: $shifts,
            );
        }

        return $sets;
    }

    /**
     * The schedule on $date out of a set loaded by setsFor() (or a plain list
     * of periods loaded by periodsFor()).
     *
     * @param  ScheduleSet|array<int, WorkSchedule>  $set
     */
    public function pick(ScheduleSet|array $set, string $date): DaySchedule
    {
        if (is_array($set)) {
            $set = new ScheduleSet($set);
        }

        $override = $set->overrides[$date] ?? null;
        if ($override !== null) {
            $shift = $override->shift_id !== null ? ($set->shifts[(int) $override->shift_id] ?? null) : null;

            return $shift !== null
                ? $this->fromShift($shift, DaySchedule::SourceOverride, (string) $override->reason)
                : $this->offDay($this->underlying($set, $date), DaySchedule::SourceOverride, (string) $override->reason);
        }

        foreach ($set->assignments as $assignment) {
            if (! $assignment->covers($date)) {
                continue;
            }

            $shiftId = $assignment->shiftIdOn($date);
            $shift = $shiftId !== null ? ($set->shifts[$shiftId] ?? null) : null;

            return $shift !== null
                ? $this->fromShift($shift, DaySchedule::SourceRoster, $assignment->roster?->name)
                : $this->offDay($this->underlying($set, $date), DaySchedule::SourceRoster, $assignment->roster?->name);
        }

        return $this->underlying($set, $date);
    }

    public function fromPeriod(WorkSchedule $period): DaySchedule
    {
        $start = $this->toSeconds((string) $period->start_time);

        return new DaySchedule(
            startSeconds: $start,
            endSeconds: $this->endSeconds($start, (string) $period->end_time),
            requiredMinutes: (int) $period->required_minutes,
            workingDays: array_values(array_map('intval', (array) $period->working_days)),
            flexibleMinutes: (int) $period->flexible_minutes,
            scheduleId: (int) $period->id,
            name: $period->name,
            breakMinutes: (int) ($period->break_minutes ?? 0),
            source: DaySchedule::SourceSchedule,
        );
    }

    /** A shift as the schedule of one day (every weekday works: the roster decides the days off). */
    public function fromShift(Shift $shift, string $source = DaySchedule::SourceRoster, ?string $note = null): DaySchedule
    {
        return new DaySchedule(
            startSeconds: $shift->startSeconds(),
            endSeconds: $shift->endSeconds(),
            requiredMinutes: (int) $shift->required_minutes,
            workingDays: [1, 2, 3, 4, 5, 6, 7],
            flexibleMinutes: (int) $shift->flexible_minutes,
            name: (string) $shift->name,
            breakMinutes: (int) $shift->break_minutes,
            source: $source,
            shiftId: (int) $shift->id,
            shiftCode: (string) $shift->code,
            color: (string) $shift->color,
            note: $note,
        );
    }

    public function timezoneFor(Employee $employee): string
    {
        foreach ([$employee->time_zone, $employee->calendar?->timezone, config('app.timezone')] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    /**
     * The attendance date of a punch at $nowUtc: the employee-local calendar
     * date, or the previous date when the punch belongs to the previous
     * day's (overnight) shift. The date of a check-in owns the whole shift.
     */
    public function attendanceDateFor(Employee $employee, CarbonImmutable $nowUtc): string
    {
        $local = $nowUtc->setTimezone($this->timezoneFor($employee));

        if (! static::shiftsAvailable() && ! static::schedulesAvailable()) {
            return $local->toDateString();
        }

        return $this->attributeLocal($this->setAround($employee, $nowUtc), $local);
    }

    /** The employee's schedule set covering the local date of $nowUtc and the day before (attribution and the day's window). */
    public function setAround(Employee $employee, CarbonImmutable $nowUtc): ScheduleSet
    {
        $local = $nowUtc->setTimezone($this->timezoneFor($employee));

        return $this->setsFor([$employee], $local->subDay()->toDateString(), $local->toDateString())[(int) $employee->id] ?? new ScheduleSet;
    }

    /**
     * Which attendance date a punch at local time $local belongs to, given
     * the employee's schedule set covering the previous and the current date.
     *
     * After midnight a punch belongs to the previous day's shift when it is
     * before that shift's end plus the overnight tolerance; between the end
     * and end + tolerance, the current day's shift wins once it has started
     * or when its start is nearer than the previous end.
     *
     * @param  ScheduleSet|array<int, WorkSchedule>  $set
     */
    public function attributeLocal(ScheduleSet|array $set, CarbonImmutable $local): string
    {
        $date = $local->toDateString();
        $previous = $local->subDay()->toDateString();
        $schedule = $this->pick($set, $previous);

        if (! $schedule->isWorkingDay($previous)) {
            return $date;
        }

        $midnight = $local->startOfDay();
        $secondsNow = (int) $midnight->diffInSeconds($local);
        // The previous shift's end, in seconds after today's midnight (negative when it ended before midnight).
        $previousEnd = $schedule->endSeconds - 86400;
        $tolerance = max(0, (int) config('attendance.shifts.overnight_tolerance_minutes', 240)) * 60;

        if ($secondsNow >= $previousEnd + $tolerance) {
            return $date;
        }

        if ($secondsNow < $previousEnd) {
            return $previous;
        }

        $today = $this->pick($set, $date);
        if ($today->isWorkingDay($date)) {
            $todayStart = $today->startSeconds;
            if ($secondsNow >= $todayStart || ($secondsNow - $previousEnd) >= ($todayStart - $secondsNow)) {
                return $date;
            }
        }

        return $previous;
    }

    /**
     * $set: the employee's schedule set when the caller already loaded one
     * covering $attendanceDate (saves the queries).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} scheduled start/end in UTC, or nulls
     *                                                         (no calendar, day off, or any calendar failure)
     */
    public function scheduledWindowFor(Employee $employee, string $attendanceDate, ?ScheduleSet $set = null): array
    {
        // A roster, an override or an effective-dated work schedule wins over the employee's calendar.
        try {
            $schedule = $set !== null ? $this->pick($set, $attendanceDate) : $this->scheduleFor($employee, $attendanceDate);
            if (! $schedule->isDefault()) {
                if (! $schedule->isWorkingDay($attendanceDate)) {
                    return [null, null];
                }

                $midnight = CarbonImmutable::parse($attendanceDate.' 00:00:00', $this->timezoneFor($employee));

                return [$midnight->addSeconds($schedule->startSeconds)->utc(), $midnight->addSeconds($schedule->endSeconds)->utc()];
            }
        } catch (Throwable $e) {
            Log::warning('Work schedule resolution failed: '.$e->getMessage(), ['employee_id' => $employee->id]);
        }

        $calendar = $employee->calendar;
        if (! $calendar) {
            return [null, null];
        }

        try {
            $timezone = $this->timezoneFor($employee);

            // Calendar::getAttendanceIntervalsBatch() (type-hinted to mutable
            // Carbon) lays a calendar's hour_from/hour_to out as UTC wall-clock
            // times, whatever timezone is passed. So ask it for the day in UTC
            // and reinterpret those wall-clock values in the employee's own
            // timezone: "09:00-17:00" then means 09:00-17:00 local. This still
            // reuses the calendar's weekday, date-range and two-week logic.
            $dayStart = Carbon::parse($attendanceDate.' 00:00:00', 'UTC');
            $dayEnd = $dayStart->copy()->endOfDay();

            $intervals = collect($calendar->getAttendanceIntervalsBatch($dayStart, $dayEnd, timezone: 'UTC')[null] ?? []);
            if ($intervals->isEmpty()) {
                return [null, null];
            }

            $toLocal = fn ($moment): CarbonImmutable => CarbonImmutable::parse($moment->format('Y-m-d H:i:s'), $timezone)->utc();

            return [
                $intervals->map(fn (array $interval) => $toLocal($interval[0]))->min(),
                $intervals->map(fn (array $interval) => $toLocal($interval[1]))->max(),
            ];
        } catch (Throwable $e) {
            // A schedule failure must never block attendance; it only means
            // late/early minutes are not computed (same as no calendar).
            Log::warning('Attendance schedule resolution failed: '.$e->getMessage(), ['employee_id' => $employee->id]);

            return [null, null];
        }
    }

    /** The work schedule (or company default) underneath any roster / override. */
    private function underlying(ScheduleSet $set, string $date): DaySchedule
    {
        foreach ($set->periods as $period) {
            if ($period->covers($date)) {
                return $this->fromPeriod($period);
            }
        }

        return $this->defaultSchedule();
    }

    /** A roster / override day off: no working days, the times of the schedule underneath. */
    private function offDay(DaySchedule $base, string $source, ?string $note): DaySchedule
    {
        return new DaySchedule(
            startSeconds: $base->startSeconds,
            endSeconds: $base->endSeconds,
            requiredMinutes: $base->requiredMinutes,
            workingDays: [],
            flexibleMinutes: $base->flexibleMinutes,
            scheduleId: $base->scheduleId,
            name: 'Off',
            breakMinutes: $base->breakMinutes,
            source: $source,
            note: $note,
        );
    }

    /** End seconds after the start date's midnight: an end at or before the start is the next day. */
    private function endSeconds(int $startSeconds, string $end): int
    {
        $seconds = $this->toSeconds($end);

        return $seconds <= $startSeconds ? $seconds + 86400 : $seconds;
    }

    private function toSeconds(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 3600 + $minutes * 60;
    }
}
