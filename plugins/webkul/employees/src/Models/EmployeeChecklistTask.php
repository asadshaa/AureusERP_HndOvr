<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One task of an employee's onboarding or exit checklist. assignee_id null
 * means "the company's HR" (ApprovalEngine::resolveHrApproversForCompany()).
 *
 * @property string $status
 * @property string $responsible_role
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $scheduled_for
 */
class EmployeeChecklistTask extends Model
{
    protected $table = 'employees_employee_checklist_tasks';

    protected $fillable = [
        'company_id',
        'checklist_id',
        'employee_id',
        'template_task_id',
        'code',
        'title',
        'description',
        'responsible_role',
        'assignee_id',
        'due_date',
        'document_type_id',
        'action_key',
        'status',
        'completion_source',
        'note',
        'completed_by',
        'completed_at',
        'employee_document_id',
        'scheduled_for',
        'scheduled_by',
        'last_reminded_on',
        'sort',
    ];

    protected $casts = [
        'due_date'         => 'immutable_date',
        'scheduled_for'    => 'immutable_date',
        'last_reminded_on' => 'immutable_date',
        'completed_at'     => 'datetime',
        'sort'             => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'checklist_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id')->withTrashed();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ChecklistKinds::TaskPending);
    }

    public function scopeOverdue(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        return $query->pending()
            ->whereNotNull($query->qualifyColumn('due_date'))
            ->whereDate($query->qualifyColumn('due_date'), '<', ($today ?? ChecklistKinds::today())->toDateString());
    }

    public function isPending(): bool
    {
        return $this->status === ChecklistKinds::TaskPending;
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->isPending()
            && $this->due_date !== null
            && $this->due_date->toDateString() < ($today ?? ChecklistKinds::today())->toDateString();
    }

    /** "Due 30 Oct", "Overdue since 28 Oct", "Done 29 Oct" ... */
    public function dueLabel(?CarbonImmutable $today = null): string
    {
        if (! $this->isPending()) {
            return ChecklistKinds::taskStatuses()[$this->status] ?? ucfirst($this->status);
        }

        if ($this->due_date === null) {
            return 'No due date';
        }

        $today ??= ChecklistKinds::today();
        $days = (int) $today->diffInDays(CarbonImmutable::parse($this->due_date->toDateString(), ChecklistKinds::timezone()), false);

        return match (true) {
            $days < 0   => 'Overdue since '.$this->due_date->format('d M'),
            $days === 0 => 'Due today',
            default     => 'Due '.$this->due_date->format('d M'),
        };
    }
}
