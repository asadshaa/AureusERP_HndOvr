<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Carbon\CarbonImmutable;

/**
 * The schedule that applies to one employee on one date, resolved by
 * AttendanceScheduleResolver in this order: a day override > the roster
 * (employee, then department) > the employee's effective-dated
 * WorkSchedule > the company default (config office hours).
 *
 * Times are seconds after the local midnight of the attendance date (the
 * date the shift starts). An overnight shift ends after midnight, so its
 * endSeconds is above 86400 (22:00-06:00 = 79200-108000).
 *
 * source: default | schedule | roster | override. A roster or override day
 * off has no working days (isWorkingDay() is false) and keeps the times of
 * the schedule underneath for off-day work.
 */
final readonly class DaySchedule
{
    public const SourceDefault = 'default';

    public const SourceSchedule = 'schedule';

    public const SourceRoster = 'roster';

    public const SourceOverride = 'override';

    /**
     * @param  array<int, int>  $workingDays  ISO weekday numbers (1 = Monday ... 7 = Sunday)
     */
    public function __construct(
        public int $startSeconds,
        public int $endSeconds,
        public int $requiredMinutes,
        public array $workingDays,
        public int $flexibleMinutes = 0,
        public ?int $scheduleId = null,
        public ?string $name = null,
        public int $breakMinutes = 0,
        public string $source = self::SourceDefault,
        public ?int $shiftId = null,
        public ?string $shiftCode = null,
        public ?string $color = null,
        public ?string $note = null,
    ) {}

    /** The company default (no schedule, roster or override). */
    public function isDefault(): bool
    {
        return $this->source === self::SourceDefault && $this->scheduleId === null;
    }

    /** A roster / override day: a shift or a day off. */
    public function isRostered(): bool
    {
        return in_array($this->source, [self::SourceRoster, self::SourceOverride], true);
    }

    /** A roster / override day off. */
    public function isOff(): bool
    {
        return $this->isRostered() && $this->workingDays === [];
    }

    public function isOvernight(): bool
    {
        return $this->endSeconds > 86400;
    }

    /** Whether $date (Y-m-d) is one of the schedule's working weekdays. */
    public function isWorkingDay(string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $this->workingDays, true);
    }

    public function startClock(): string
    {
        return self::clock($this->startSeconds);
    }

    public function endClock(): string
    {
        return self::clock($this->endSeconds);
    }

    /** "10:00–18:00" (plus ", flexible 60 min", ", break 30 min"); a shift is "Night 22:00–06:00"; a day off "Off". */
    public function label(): string
    {
        if ($this->isOff()) {
            return 'Off';
        }

        return ($this->shiftId !== null && $this->name ? $this->name.' ' : '')
            .$this->startClock().'–'.$this->endClock()
            .($this->flexibleMinutes > 0 ? ', flexible '.$this->flexibleMinutes.' min' : '')
            .($this->breakMinutes > 0 ? ', break '.$this->breakMinutes.' min' : '');
    }

    /** Short roster-grid text: the shift code, "Off", or the hours. */
    public function shortLabel(): string
    {
        return match (true) {
            $this->isOff()            => 'Off',
            $this->shiftCode !== null => $this->shiftCode,
            default                   => $this->startClock().'–'.$this->endClock(),
        };
    }

    /** A stable key per distinct schedule (the monthly report's "main schedule" of an employee). */
    public function key(): string
    {
        return match (true) {
            $this->shiftId !== null    => 'shift:'.$this->shiftId,
            $this->scheduleId !== null => (string) $this->scheduleId,
            default                    => 'default',
        };
    }

    /**
     * The shape MonthlyAttendanceReport::calculateDay() takes as $day['schedule'].
     *
     * @return array{start: int, end: int, required: int, flexible: int, break: int}
     */
    public function forCalculation(): array
    {
        return [
            'start'    => $this->startSeconds,
            'end'      => $this->endSeconds,
            'required' => $this->requiredMinutes,
            'flexible' => $this->flexibleMinutes,
            'break'    => $this->breakMinutes,
        ];
    }

    /** @return array{id: ?int, name: ?string, start: string, end: string, required_minutes: int, working_days: array<int, int>, flexible_minutes: int, label: string, break_minutes: int, source: string, shift_id: ?int, shift_code: ?string, color: ?string, overnight: bool} */
    public function toArray(): array
    {
        return [
            'id'               => $this->scheduleId,
            'name'             => $this->name,
            'start'            => $this->startClock(),
            'end'              => $this->endClock(),
            'required_minutes' => $this->requiredMinutes,
            'working_days'     => $this->workingDays,
            'flexible_minutes' => $this->flexibleMinutes,
            'label'            => $this->label(),
            'break_minutes'    => $this->breakMinutes,
            'source'           => $this->source,
            'shift_id'         => $this->shiftId,
            'shift_code'       => $this->shiftCode,
            'color'            => $this->color,
            'overnight'        => $this->isOvernight(),
        ];
    }

    private static function clock(int $seconds): string
    {
        $seconds %= 86400;

        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
