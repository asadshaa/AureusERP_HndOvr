<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Webkul\Employee\Database\Factories\EmployeeWorkLocationAssignmentFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * An ADDITIONAL (or temporary / approved-remote-day) workplace for an
 * employee, on top of Employee.work_location_id (the primary workplace).
 */
class EmployeeWorkLocationAssignment extends Model
{
    use HasFactory;

    protected $table = 'employees_employee_work_location_assignments';

    protected $fillable = [
        'company_id',
        'employee_id',
        'work_location_id',
        'valid_from',
        'valid_until',
        'reason',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from'  => 'date',
            'valid_until' => 'date',
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

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class)->withTrashed();
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Assignments whose validity window covers $date (open-ended on either side when null). */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date));
    }

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->assigned_by ??= Auth::id();
        });

        static::saving(function (self $assignment): void {
            // Eloquent fires "saving" BEFORE "creating", so the company must be defaulted
            // here or a create that omits it (the relation manager) would fail validation.
            $assignment->company_id ??= Employee::query()->whereKey($assignment->employee_id)->value('company_id');

            $employeeCompany = Employee::query()->whereKey($assignment->employee_id)->value('company_id');
            $locationCompany = WorkLocation::withTrashed()->whereKey($assignment->work_location_id)->value('company_id');

            if ($employeeCompany === null || $locationCompany === null
                || (int) $employeeCompany !== (int) $assignment->company_id
                || (int) $locationCompany !== (int) $assignment->company_id) {
                throw new InvalidArgumentException('The employee and the work location must be in the same company. Choose a work location from the employee\'s company.');
            }

            if ($assignment->valid_from && $assignment->valid_until && $assignment->valid_until->lt($assignment->valid_from)) {
                throw new InvalidArgumentException('The end date cannot be before the start date.');
            }
        });
    }

    protected static function newFactory(): EmployeeWorkLocationAssignmentFactory
    {
        return EmployeeWorkLocationAssignmentFactory::new();
    }
}
