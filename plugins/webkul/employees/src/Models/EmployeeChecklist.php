<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One onboarding or exit run of an employee (see EmployeeChecklistService).
 *
 * @property string $type
 * @property string $status
 * @property CarbonImmutable $anchor_date joining date (onboarding) or last working day (exit)
 * @property CarbonImmutable|null $last_working_day
 * @property CarbonImmutable|null $leaving_date
 * @property array<string, mixed>|null $previous_values
 */
class EmployeeChecklist extends Model
{
    protected $table = 'employees_employee_checklists';

    protected $fillable = [
        'company_id',
        'employee_id',
        'template_id',
        'type',
        'status',
        'open_key',
        'anchor_date',
        'last_working_day',
        'leaving_date',
        'notice_period_days',
        'departure_reason_id',
        'departure_description',
        'previous_values',
        'auto_started',
        'started_by',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'user_disabled_at',
        'disabled_user_id',
    ];

    protected $casts = [
        'anchor_date'        => 'immutable_date',
        'last_working_day'   => 'immutable_date',
        'leaving_date'       => 'immutable_date',
        'notice_period_days' => 'integer',
        'previous_values'    => 'array',
        'auto_started'       => 'boolean',
        'completed_at'       => 'datetime',
        'cancelled_at'       => 'datetime',
        'user_disabled_at'   => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }

    public function departureReason(): BelongsTo
    {
        return $this->belongsTo(DepartureReason::class, 'departure_reason_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EmployeeChecklistTask::class, 'checklist_id')->orderBy('sort')->orderBy('id');
    }

    public static function openKey(int $employeeId, string $type): string
    {
        return $employeeId.':'.$type;
    }

    public function isOpen(): bool
    {
        return $this->status === ChecklistKinds::ChecklistOpen;
    }

    public function typeLabel(): string
    {
        return ChecklistKinds::typeLabel($this->type);
    }

    /** "Joining date" or "Last working day", with the date. */
    public function anchorLabel(): string
    {
        return ($this->type === ChecklistKinds::Offboarding ? 'Last working day ' : 'Joining date ').$this->anchor_date?->format('d M Y');
    }
}
