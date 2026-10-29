<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

/**
 * One employee's monthly attendance report (employee, summary, days and the
 * schedule used) as it was when HR finalised the month. The monthly report
 * and its Excel export read these instead of recalculating while the month
 * is locked. Replaced on the next finalise.
 */
class AttendanceMonthSnapshot extends Model
{
    protected $table = 'employees_attendance_month_snapshots';

    protected $fillable = [
        'lock_id',
        'company_id',
        'month',
        'employee_id',
        'data',
        'finalised_at',
        'finalised_by',
    ];

    protected function casts(): array
    {
        return [
            'data'         => 'array',
            'finalised_at' => 'datetime',
        ];
    }

    /** Keeps 168.0 a float (hours, penalty %), so the frozen report reads back exactly as it was built. */
    protected function asJson($value, $flags = 0): string|false
    {
        return parent::asJson($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(AttendanceMonthLock::class, 'lock_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function finaliser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }
}
