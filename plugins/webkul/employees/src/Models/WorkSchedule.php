<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One effective-dated work schedule of an employee: start / end time,
 * required minutes, working weekdays and an optional flexible window, valid
 * from effective_from to effective_to (open-ended when null). Periods of one
 * employee never overlap; a day without a period uses the company default
 * (config/attendance.php office hours). Resolved by AttendanceScheduleResolver.
 *
 * Enforced on every save, whatever the path: same company as the employee,
 * a valid time window and weekdays, no overlap, and no change to days of a
 * finalised attendance month (AttendanceMonthLockService).
 */
class WorkSchedule extends Model
{
    protected $table = 'employees_work_schedules';

    /** Largest flexible window (minutes the start may slide). */
    public const MaxFlexibleMinutes = 240;

    protected $fillable = [
        'company_id',
        'employee_id',
        'name',
        'start_time',
        'end_time',
        'required_minutes',
        'working_days',
        'flexible_minutes',
        'effective_from',
        'effective_to',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'working_days'     => 'array',
            'required_minutes' => 'integer',
            'flexible_minutes' => 'integer',
            'effective_from'   => 'date',
            'effective_to'     => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Periods covering any day of [$from, $to] (inclusive, Y-m-d). */
    public function scopeCovering(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $to)
            ->where(fn (Builder $inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));
    }

    public function covers(string $date): bool
    {
        return $this->effective_from->toDateString() <= $date
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $date);
    }

    /** "10:00" from the stored "10:00:00". */
    public static function clock(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }

    public function label(): string
    {
        $days = collect((array) $this->working_days)->map(fn ($day): int => (int) $day)->sort()->values()->all();

        return trim(($this->name ? $this->name.': ' : '')
            .static::clock($this->start_time).'–'.static::clock($this->end_time)
            .', '.intdiv((int) $this->required_minutes, 60).'h'.((int) $this->required_minutes % 60 ? ' '.((int) $this->required_minutes % 60).'m' : '')
            .' ('.static::weekdaysLabel($days).')'
            .((int) $this->flexible_minutes > 0 ? ', flexible '.(int) $this->flexible_minutes.' min' : ''));
    }

    /** @param  array<int, int>  $days  ISO weekday numbers */
    public static function weekdaysLabel(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        sort($days);

        if ($days === [1, 2, 3, 4, 5]) {
            return 'Mon–Fri';
        }
        if ($days === [1, 2, 3, 4, 5, 6]) {
            return 'Mon–Sat';
        }

        return implode(', ', array_map(fn (int $day): string => $names[$day] ?? (string) $day, $days));
    }

    /** @return array<int, string> ISO weekday number => name, for the form */
    public static function weekdayOptions(): array
    {
        return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $schedule): void {
            $schedule->validateSchedule();

            app(AttendanceMonthLockService::class)->guardWorkSchedule($schedule);

            $schedule->updated_by = Auth::id() ?? $schedule->updated_by;
            if (! $schedule->exists) {
                $schedule->created_by ??= Auth::id();
            }
        });

        static::deleting(function (self $schedule): void {
            app(AttendanceMonthLockService::class)->guardWorkSchedule($schedule, deleting: true);
        });
    }

    /** @throws ValidationException */
    protected function validateSchedule(): void
    {
        $employee = Employee::query()->withTrashed()->find($this->employee_id);
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'Choose the employee this schedule is for.']);
        }

        $this->company_id ??= $employee->company_id;
        if ((int) $employee->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages(['employee_id' => 'A work schedule must belong to the employee\'s own company.']);
        }

        $start = $this->toMinutes($this->start_time);
        $end = $this->toMinutes($this->end_time);
        if ($start === null || $end === null) {
            throw ValidationException::withMessages(['start_time' => 'Give the start and end time as HH:MM.']);
        }
        if ($end <= $start) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time (overnight shifts are not supported).']);
        }
        $this->start_time = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
        $this->end_time = sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60);

        $required = (int) $this->required_minutes;
        if ($required < 1 || $required > 1440) {
            throw ValidationException::withMessages(['required_minutes' => 'Required minutes must be between 1 and 1440.']);
        }

        $flexible = (int) ($this->flexible_minutes ?? 0);
        if ($flexible < 0 || $flexible > self::MaxFlexibleMinutes) {
            throw ValidationException::withMessages(['flexible_minutes' => 'The flexible window must be between 0 and '.self::MaxFlexibleMinutes.' minutes.']);
        }
        $this->flexible_minutes = $flexible;

        $days = collect((array) $this->working_days)->map(fn ($day): int => (int) $day)->filter(fn (int $day): bool => $day >= 1 && $day <= 7)->unique()->sort()->values()->all();
        if ($days === []) {
            throw ValidationException::withMessages(['working_days' => 'Choose at least one working day.']);
        }
        $this->working_days = $days;

        if (! $this->effective_from) {
            throw ValidationException::withMessages(['effective_from' => 'Give the date the schedule starts.']);
        }
        if ($this->effective_to && $this->effective_to->lt($this->effective_from)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date must be on or after the start date.']);
        }

        $from = $this->effective_from->toDateString();
        $to = $this->effective_to?->toDateString() ?? '9999-12-31';

        $overlap = static::query()
            ->where('employee_id', $this->employee_id)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->covering($from, $to)
            ->orderBy('effective_from')
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => sprintf(
                'This period overlaps the schedule from %s%s. End that schedule first, or choose other dates.',
                CarbonImmutable::parse($overlap->effective_from)->format('d M Y'),
                $overlap->effective_to ? ' to '.CarbonImmutable::parse($overlap->effective_to)->format('d M Y') : ' (no end date)',
            )]);
        }
    }

    private function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        return $hours <= 23 && $minutes <= 59 ? $hours * 60 + $minutes : null;
    }
}
