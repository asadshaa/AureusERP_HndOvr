<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Enums\DeviceImportRowStatus;

/**
 * One row of an uploaded device file: what the file said (kept for audit)
 * and what the import made of it. A row with a punch_key is a live imported
 * punch.
 */
class AttendanceImportRow extends Model
{
    protected $table = 'employees_attendance_import_rows';

    protected $fillable = [
        'batch_id',
        'company_id',
        'row_number',
        'identifier',
        'employee_code',
        'employee_name',
        'raw_datetime',
        'local_datetime',
        'raw_direction',
        'direction',
        'raw',
        'employee_id',
        'punched_at',
        'attendance_date',
        'status',
        'message',
        'punch_key',
        'attendance_record_id',
    ];

    protected function casts(): array
    {
        return [
            'raw'             => 'array',
            'punched_at'      => 'datetime',
            'attendance_date' => 'date',
            'row_number'      => 'integer',
            'status'          => DeviceImportRowStatus::class,
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

    /** @param  Builder<self>  $query */
    public function scopeLivePunches(Builder $query): Builder
    {
        return $query->whereNotNull('punch_key');
    }

    public static function punchKey(int $employeeId, string $punchedAtUtc): string
    {
        return $employeeId.'|'.$punchedAtUtc;
    }
}
