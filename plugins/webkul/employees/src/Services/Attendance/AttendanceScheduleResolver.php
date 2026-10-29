<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\Data\DaySchedule;

/**
 * Timezone, attendance-date and scheduled-window resolution. Reuses the
 * existing Employee.time_zone -> Calendar.timezone -> app timezone chain
 * (Company has no timezone column) and Calendar::getAttendanceIntervalsBatch()
 * for the day's working window; nothing is invented here.
 *
 * Work schedule by date (HR parity plan 2.2): an employee's effective-dated
 * WorkSchedule covering the date, else the company default -- the office
 * hours of config/attendance.php (monthly_report.office_start / office_end,
 * daily_required_minutes, weekend_days). The monthly report, Today's
 * attendance, the late check-in notice and scheduledWindowFor() all resolve
 * the day through here.
 */
class AttendanceScheduleResolver
{
    private static ?bool $schedulesTableExists = null;

    public static function schedulesAvailable(): bool
    {
        return self::$schedulesTableExists ??= Schema::hasTable('employees_work_schedules');
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

        return $this->pick($this->periodsFor([$employeeId], $date, $date)[$employeeId] ?? [], $date);
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
     * The schedule on $date out of periods loaded by periodsFor().
     *
     * @param  array<int, WorkSchedule>  $periods
     */
    public function pick(array $periods, string $date): DaySchedule
    {
        foreach ($periods as $period) {
            if ($period->covers($date)) {
                return $this->fromPeriod($period);
            }
        }

        return $this->defaultSchedule();
    }

    public function fromPeriod(WorkSchedule $period): DaySchedule
    {
        return new DaySchedule(
            startSeconds: $this->toSeconds((string) $period->start_time),
            endSeconds: $this->toSeconds((string) $period->end_time),
            requiredMinutes: (int) $period->required_minutes,
            workingDays: array_values(array_map('intval', (array) $period->working_days)),
            flexibleMinutes: (int) $period->flexible_minutes,
            scheduleId: (int) $period->id,
            name: $period->name,
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

    /** The employee-local calendar date of $nowUtc (the date of a check-in owns the whole shift). */
    public function attendanceDateFor(Employee $employee, CarbonImmutable $nowUtc): string
    {
        return $nowUtc->setTimezone($this->timezoneFor($employee))->toDateString();
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} scheduled start/end in UTC, or nulls
     *                                                         (no calendar, day off, or any calendar failure)
     */
    public function scheduledWindowFor(Employee $employee, string $attendanceDate): array
    {
        // An effective-dated work schedule wins over the employee's calendar.
        try {
            $schedule = $this->scheduleFor($employee, $attendanceDate);
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

    private function toSeconds(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 3600 + $minutes * 60;
    }
}
