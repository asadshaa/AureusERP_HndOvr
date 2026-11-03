<?php

namespace Webkul\Employee\Services\Attendance;

use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\Data\DaySchedule;
use Webkul\Employee\Services\Attendance\Data\ScheduleSet;

/**
 * The attendance status of a closed day, from its worked time (the sum of its
 * check-in / check-out intervals) against the day's resolved schedule
 * (AttendanceScheduleResolver: override > roster > work schedule > calendar >
 * company default):
 *
 *  - nothing worked                                       => absent;
 *  - at least the day's required minutes minus
 *    hr_attendance_geofence.full_day_tolerance_minutes     => present (remote for a remote day);
 *  - anything less                                        => half_day.
 *
 * A day that is not a working day of the schedule (weekend, roster day off)
 * has nothing required: any work on it is present (remote). A day without
 * required minutes falls back to hr_attendance_geofence.full_day_min_hours.
 * Leave and holiday days keep their status.
 */
class AttendanceStatusClassifier
{
    /** Statuses a worked-time calculation never replaces. */
    public const KEPT = ['leave', 'holiday'];

    public function __construct(
        private readonly AttendanceScheduleResolver $schedules,
    ) {}

    /** $set: the employee's schedule set when the caller already loaded one covering $date. */
    public function classify(Employee $employee, string $date, int $workedMinutes, bool $remote, ?ScheduleSet $set = null): string
    {
        $attended = $remote ? 'remote' : 'present';

        if ($workedMinutes <= 0) {
            return 'absent';
        }

        $schedule = $set !== null ? $this->schedules->pick($set, $date) : $this->schedules->scheduleFor($employee, $date);
        if (! $schedule->isWorkingDay($date)) {
            return $attended;
        }

        return $workedMinutes >= $this->fullDayMinutes($schedule) ? $attended : 'half_day';
    }

    /** The worked minutes that make a full day on this schedule. */
    public function fullDayMinutes(DaySchedule $schedule): int
    {
        if ($schedule->requiredMinutes > 0) {
            $tolerance = max(0, (int) config('hr_attendance_geofence.full_day_tolerance_minutes', 30));

            return max(1, $schedule->requiredMinutes - $tolerance);
        }

        return max(1, (int) round((float) config('hr_attendance_geofence.full_day_min_hours', 7.5) * 60));
    }

    /**
     * The status of a record after its times changed (an approved correction
     * or missed day): recomputed from its intervals once the day is closed;
     * an open day, or a leave / holiday day, keeps its status.
     */
    public function statusForRecord(AttendanceRecord $record, AttendanceIntervalService $intervals): string
    {
        $current = (string) $record->status;
        $record->loadMissing('employee');

        if (in_array($current, self::KEPT, true) || ! $record->employee || ! $record->attendance_date) {
            return $current;
        }

        $dayIntervals = $intervals->intervalsFor($record);
        $open = $dayIntervals->isEmpty()
            ? $record->check_in !== null && $record->check_out === null
            : $dayIntervals->contains(fn ($interval): bool => $interval->isOpen());

        if ($record->check_in === null || $open) {
            return $current;
        }

        $workedMinutes = $dayIntervals->isNotEmpty()
            ? (int) $dayIntervals->sum(fn ($interval): int => $interval->durationMinutes())
            : (int) $record->check_in->diffInMinutes($record->check_out);

        return $this->classify($record->employee, $record->attendance_date->toDateString(), $workedMinutes, $current === 'remote');
    }
}
