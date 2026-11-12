<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A manager for one purpose (leave, claims, ...) that differs from the
 * employee's line manager. No row means the line manager approves.
 */
class ReportingAssignment extends Model
{
    protected $table = 'employees_reporting_assignments';

    protected $fillable = ['company_id', 'employee_id', 'purpose', 'manager_employee_id', 'created_by'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
