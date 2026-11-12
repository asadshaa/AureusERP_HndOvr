<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One change of a line manager (purpose null) or of a purpose manager, kept
 * for good. Status: applied, pending (HR's change to her own line, waiting for
 * a second person) or rejected.
 */
class ReportingChange extends Model
{
    public const Applied = 'applied';

    public const Pending = 'pending';

    public const Rejected = 'rejected';

    protected $table = 'employees_reporting_changes';

    protected $fillable = [
        'company_id', 'employee_id', 'purpose', 'old_manager_employee_id', 'new_manager_employee_id',
        'status', 'reason', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function oldManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'old_manager_employee_id');
    }

    public function newManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'new_manager_employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
