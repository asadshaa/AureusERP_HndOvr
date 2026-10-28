<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One check-in / check-out pair of an attendance day. The parent
 * AttendanceRecord is the day summary; AttendanceIntervalService keeps the
 * two in step. Corrections void intervals (voided_at) instead of deleting
 * them, so the original punches stay auditable.
 */
class AttendanceInterval extends Model
{
    protected $table = 'employees_attendance_intervals';

    protected $fillable = [
        'company_id',
        'employee_id',
        'attendance_record_id',
        'check_in',
        'check_out',
        'source',
        'start_verification_id',
        'end_verification_id',
        'verification_status',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'check_in'  => 'datetime',
            'check_out' => 'datetime',
            'voided_at' => 'datetime',
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

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isOpen(): bool
    {
        return $this->check_out === null;
    }

    public function durationMinutes(): int
    {
        return $this->check_out ? max(0, (int) $this->check_in->diffInMinutes($this->check_out)) : 0;
    }

    protected static function booted(): void
    {
        static::saving(function (self $interval): void {
            $record = $interval->attendanceRecord;
            if ($record && ((int) $record->company_id !== (int) $interval->company_id || (int) $record->employee_id !== (int) $interval->employee_id)) {
                throw new InvalidArgumentException('An attendance interval must belong to the same company and employee as its day.');
            }

            if ($interval->check_out !== null && $interval->check_out->lessThanOrEqualTo($interval->check_in)) {
                throw new InvalidArgumentException('Check-out must be after check-in.');
            }
        });
    }
}
