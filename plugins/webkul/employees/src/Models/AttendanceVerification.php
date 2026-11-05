<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\Employee\Database\Factories\AttendanceVerificationFactory;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Models\Builders\AttendanceVerificationBuilder;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One row per attendance attempt (accepted or not) -- audit evidence, never
 * worked time. Effectively append-only: only review columns may change,
 * the attendance link may be set once, and privacy pruning may null the
 * precise-location columns. Rows are never deleted through the model.
 */
class AttendanceVerification extends Model
{
    use HasFactory;

    protected $table = 'employees_attendance_verifications';

    protected $guarded = [];

    /** Sensitive columns must be read explicitly, behind the evidence permission. */
    protected $hidden = ['latitude', 'longitude', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'action'             => AttendanceVerificationAction::class,
            'method'             => AttendanceVerificationMethod::class,
            'result'             => AttendanceVerificationResult::class,
            'accepted'           => 'boolean',
            'latitude'           => 'decimal:7',
            'longitude'          => 'decimal:7',
            'accuracy_meters'    => 'decimal:2',
            'distance_meters'    => 'decimal:2',
            'geofence_snapshot'  => 'array',
            'flags'              => 'array',
            'metadata'           => 'array',
            'client_captured_at' => 'datetime',
            'server_recorded_at' => 'datetime',
            'reviewed_at'        => 'datetime',
        ];
    }

    public function newEloquentBuilder($query): AttendanceVerificationBuilder
    {
        return new AttendanceVerificationBuilder($query);
    }

    protected static function newFactory(): AttendanceVerificationFactory
    {
        return AttendanceVerificationFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $verification): void {
            $reviewColumns = ['review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'updated_at'];

            foreach ($verification->getDirty() as $column => $value) {
                if (in_array($column, $reviewColumns, true)) {
                    continue;
                }

                if ($column === 'attendance_record_id' && $verification->getOriginal($column) === null) {
                    continue;
                }

                if (in_array($column, ['latitude', 'longitude', 'ip_address', 'user_agent'], true) && $value === null) {
                    continue;
                }

                throw new LogicException("Location check details are kept as a permanent record, so {$column} cannot be changed.");
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Location check details are kept as a permanent record and cannot be deleted.');
        });
    }
}
