<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\RosterPresence;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A roster pattern assigned to one employee or to a whole department, from
 * start_date (day 1 of the pattern) to end_date (open-ended when null). An
 * employee's own assignment wins over their department's.
 *
 * Enforced on every save, whatever the path: exactly one target, the same
 * company as the pattern and the target, no overlap with another
 * assignment of the same target, and no change to days of a finalised
 * attendance month (AttendanceMonthLockService).
 */
class ShiftRosterAssignment extends Model
{
    protected $table = 'employees_shift_roster_assignments';

    protected $fillable = [
        'company_id',
        'roster_id',
        'employee_id',
        'department_id',
        'start_date',
        'end_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(ShiftRoster::class, 'roster_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Assignments covering any day of [$from, $to] (inclusive, Y-m-d). */
    public function scopeCovering(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $to)
            ->where(fn (Builder $inner) => $inner->whereNull('end_date')->orWhereDate('end_date', '>=', $from));
    }

    public function covers(string $date): bool
    {
        return $this->start_date->toDateString() <= $date
            && ($this->end_date === null || $this->end_date->toDateString() >= $date);
    }

    /** The shift id on $date (null = day off, or a date the assignment does not cover). */
    public function shiftIdOn(string $date): ?int
    {
        return $this->covers($date) ? $this->roster?->shiftIdOn($this->start_date->toDateString(), $date) : null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $assignment->validateAssignment();

            app(AttendanceMonthLockService::class)->guardRosterAssignment($assignment);

            $assignment->updated_by = Auth::id() ?? $assignment->updated_by;
            if (! $assignment->exists) {
                $assignment->created_by ??= Auth::id();
            }
        });

        static::deleting(function (self $assignment): void {
            app(AttendanceMonthLockService::class)->guardRosterAssignment($assignment, deleting: true);
        });

        static::saved(fn (self $assignment) => RosterPresence::forget((int) $assignment->company_id));
        static::deleted(fn (self $assignment) => RosterPresence::forget((int) $assignment->company_id));
    }

    /** @throws ValidationException */
    protected function validateAssignment(): void
    {
        $roster = ShiftRoster::query()->find($this->roster_id);
        if (! $roster) {
            throw ValidationException::withMessages(['roster_id' => 'Choose the roster pattern.']);
        }

        $this->company_id ??= $roster->company_id;
        if ((int) $roster->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages(['roster_id' => 'The roster pattern belongs to another company.']);
        }

        if (($this->employee_id === null) === ($this->department_id === null)) {
            throw ValidationException::withMessages(['employee_id' => 'Assign the pattern to one employee or to one department.']);
        }

        if ($this->employee_id !== null) {
            $companyId = Employee::query()->withTrashed()->whereKey($this->employee_id)->value('company_id');
            if ((int) $companyId !== (int) $this->company_id) {
                throw ValidationException::withMessages(['employee_id' => 'The employee belongs to another company.']);
            }
        } else {
            $companyId = Department::query()->withTrashed()->whereKey($this->department_id)->value('company_id');
            if ((int) $companyId !== (int) $this->company_id) {
                throw ValidationException::withMessages(['department_id' => 'The department belongs to another company.']);
            }
        }

        if (! $this->start_date) {
            throw ValidationException::withMessages(['start_date' => 'Give the date the pattern starts (its day 1).']);
        }
        if ($this->end_date && $this->end_date->lt($this->start_date)) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }

        $from = $this->start_date->toDateString();
        $to = $this->end_date?->toDateString() ?? '9999-12-31';

        $overlap = static::query()
            ->when($this->employee_id !== null, fn (Builder $query) => $query->where('employee_id', $this->employee_id), fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->covering($from, $to)
            ->orderBy('start_date')
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => sprintf(
                'This overlaps the roster from %s%s. End that one first, or choose other dates.',
                CarbonImmutable::parse($overlap->start_date)->format('d M Y'),
                $overlap->end_date ? ' to '.CarbonImmutable::parse($overlap->end_date)->format('d M Y') : ' (no end date)',
            )]);
        }
    }
}
