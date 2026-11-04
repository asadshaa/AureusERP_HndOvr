<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

/**
 * The working hours of an employee's working-time Calendar (Employee.calendar_id),
 * loaded once by AttendanceScheduleResolver::setsFor() so any date can be
 * resolved without further queries. Follows Calendar::getAttendanceIntervalsBatch():
 * lunch rows, resource-specific rows and display rows are not working time; a
 * two-week calendar alternates by ISO week (CalendarAttendance::getWeekType()); a
 * row's date_from / date_to limit the dates it applies to.
 *
 * Times are minutes after the local midnight of the date (the calendar's
 * hour_from / hour_to are wall-clock times in the employee's timezone).
 */
final readonly class CalendarHours
{
    /**
     * @param  array<int, array{weekday: int, slots: array<int, int>, from: int, to: int, date_from: ?string, date_to: ?string}>  $rows  weekday: ISO 1 (Monday) ... 7 (Sunday)
     */
    public function __construct(
        public int $calendarId,
        public ?string $name,
        public bool $twoWeeks,
        public array $rows,
    ) {}

    /** The calendar's working rows; null when it has none (an empty calendar does not decide anybody's schedule). */
    public static function fromCalendar(Calendar $calendar): ?self
    {
        $weekdays = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
        $rows = [];

        foreach ($calendar->attendance as $attendance) {
            $weekday = $weekdays[strtolower((string) $attendance->day_of_week)] ?? null;
            if ($weekday === null
                || $attendance->day_period === 'lunch'
                || $attendance->resource_id !== null
                || $attendance->display_type !== null) {
                continue;
            }

            $from = CalendarAttendance::toMinutes($attendance->hour_from);
            $to = CalendarAttendance::toMinutes($attendance->hour_to);
            if ($to <= $from) {
                continue;
            }

            $rows[] = [
                'weekday'   => $weekday,
                'slots'     => CalendarAttendance::weekSlotsFor($attendance->week_type),
                'from'      => $from,
                'to'        => $to,
                'date_from' => filled($attendance->date_from) ? CarbonImmutable::parse($attendance->date_from)->toDateString() : null,
                'date_to'   => filled($attendance->date_to) ? CarbonImmutable::parse($attendance->date_to)->toDateString() : null,
            ];
        }

        if ($rows === []) {
            return null;
        }

        return new self((int) $calendar->id, $calendar->name, (bool) $calendar->two_weeks_calendar, $rows);
    }

    /**
     * The working intervals of $date (Y-m-d), in start order.
     *
     * @return array<int, array{0: int, 1: int}> minutes after local midnight
     */
    public function intervalsOn(string $date): array
    {
        $day = Carbon::parse($date);
        $weekday = $day->dayOfWeekIso;
        $slot = $this->twoWeeks ? CalendarAttendance::getWeekType($day) : null;

        $intervals = [];
        foreach ($this->rows as $row) {
            if ($row['weekday'] !== $weekday
                || ($this->twoWeeks && ! in_array($slot, $row['slots'], true))
                || ($row['date_from'] !== null && $date < $row['date_from'])
                || ($row['date_to'] !== null && $date > $row['date_to'])) {
                continue;
            }

            $intervals[] = [$row['from'], $row['to']];
        }

        usort($intervals, fn (array $a, array $b): int => $a <=> $b);

        return $intervals;
    }

    /**
     * ISO weekdays that have working hours (in either week of a two-week calendar).
     *
     * @return array<int, int>
     */
    public function workingWeekdays(): array
    {
        $weekdays = array_values(array_unique(array_column($this->rows, 'weekday')));
        sort($weekdays);

        return $weekdays;
    }
}
