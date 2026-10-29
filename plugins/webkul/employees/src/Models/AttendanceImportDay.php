<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of an attendance day right after a device import (or an undo
 * rebuild) wrote it. See DeviceAttendanceImportService::fingerprint().
 */
class AttendanceImportDay extends Model
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_UNDONE = 'undone';

    protected $table = 'employees_attendance_import_days';

    protected $fillable = [
        'batch_id',
        'company_id',
        'employee_id',
        'attendance_date',
        'attendance_record_id',
        'record_created',
        'punch_count',
        'needs_review',
        'fingerprint',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'record_created'  => 'boolean',
            'needs_review'    => 'boolean',
            'punch_count'     => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
