<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Carbon\CarbonImmutable;

/**
 * The schedule that applies to one employee on one date: an employee's
 * effective-dated WorkSchedule, or the company default (config office
 * hours). Times are seconds after the local midnight.
 */
final readonly class DaySchedule
{
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
    ) {}

    public function isDefault(): bool
    {
        return $this->scheduleId === null;
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

    /** "10:00–18:00" (plus ", flexible 60 min"). */
    public function label(): string
    {
        return $this->startClock().'–'.$this->endClock().($this->flexibleMinutes > 0 ? ', flexible '.$this->flexibleMinutes.' min' : '');
    }

    /**
     * The shape MonthlyAttendanceReport::calculateDay() takes as $day['schedule'].
     *
     * @return array{start: int, end: int, required: int, flexible: int}
     */
    public function forCalculation(): array
    {
        return [
            'start'    => $this->startSeconds,
            'end'      => $this->endSeconds,
            'required' => $this->requiredMinutes,
            'flexible' => $this->flexibleMinutes,
        ];
    }

    /** @return array{id: ?int, name: ?string, start: string, end: string, required_minutes: int, working_days: array<int, int>, flexible_minutes: int, label: string} */
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
        ];
    }

    private static function clock(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
