<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

/** Audit row of an attendance month being finalised or re-opened (append-only). */
class AttendanceMonthLockEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'employees_attendance_month_lock_events';

    protected $fillable = [
        'lock_id',
        'company_id',
        'month',
        'action',
        'user_id',
        'reason',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(AttendanceMonthLock::class, 'lock_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
