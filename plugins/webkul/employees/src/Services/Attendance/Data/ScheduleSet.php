<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\ShiftOverride;
use Webkul\Employee\Models\ShiftRosterAssignment;
use Webkul\Employee\Models\WorkSchedule;

/**
 * Everything that decides one employee's schedule over a date range, loaded
 * in a few queries for many employees at once by
 * AttendanceScheduleResolver::setsFor(); AttendanceScheduleResolver::pick()
 * then resolves any date of the range without further queries.
 */
final readonly class ScheduleSet
{
    /**
     * @param  array<int, WorkSchedule>  $periods  effective-dated work schedules, in effective_from order
     * @param  array<int, ShiftRosterAssignment>  $assignments  the employee's own first, then the department's; newest start first
     * @param  array<string, ShiftOverride>  $overrides  keyed by date (Y-m-d)
     * @param  array<int, Shift>  $shifts  every shift the overrides and roster patterns use, keyed by id
     */
    public function __construct(
        public array $periods = [],
        public array $assignments = [],
        public array $overrides = [],
        public array $shifts = [],
    ) {}
}
