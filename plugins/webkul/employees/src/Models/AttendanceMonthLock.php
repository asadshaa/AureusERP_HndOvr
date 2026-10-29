<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * HR's finalise / re-open state of one company's attendance month ("Y-m",
 * in attendance.monthly_report.timezone). A finalised month is locked: see
 * AttendanceMonthLockService for what that blocks and for the snapshots.
 */
class AttendanceMonthLock extends Model
{
    public const Finalised = 'finalised';

    public const Reopened = 'reopened';

    protected $table = 'employees_attendance_month_locks';

    protected $fillable = [
        'company_id',
        'month',
        'status',
        'employees_count',
        'open_issues',
        'finalised_at',
        'finalised_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'employees_count' => 'integer',
            'open_issues'     => 'array',
            'finalised_at'    => 'datetime',
            'reopened_at'     => 'datetime',
        ];
    }

    public function isFinalised(): bool
    {
        return $this->status === self::Finalised;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function finaliser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AttendanceMonthSnapshot::class, 'lock_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceMonthLockEvent::class, 'lock_id')->orderBy('id');
    }
}
