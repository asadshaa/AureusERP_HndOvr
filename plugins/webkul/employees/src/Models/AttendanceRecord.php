<?php

namespace Webkul\Employee\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Services\Attendance\AttendanceIntervalService;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class AttendanceRecord extends Model
{
    protected $table = 'employees_attendance_records';

    protected $fillable = [
        'company_id',
        'employee_id',
        'approved_by',
        'creator_id',
        'attendance_date',
        'scheduled_start',
        'scheduled_end',
        'check_in',
        'check_out',
        'worked_hours',
        'overtime_hours',
        'late_minutes',
        'early_departure_minutes',
        'status',
        'source',
        'source_reference',
        'check_in_verification_id',
        'check_out_verification_id',
        'verification_status',
        'notes',
    ];

    /**
     * Set by GeofencedAttendanceService while it writes, so the correction
     * audit hook below does not double-log the service's own writes.
     */
    public static bool $suppressCorrectionAudit = false;

    /** Reason recorded by the deletion audit hook; set by GeofencedAttendanceService::deleteRecord(). */
    public static ?string $pendingDeletionReason = null;

    /**
     * Set by AttendanceIntervalService while it writes the day summary from
     * the intervals, so the hooks below neither recompute worked_hours from
     * first-in / last-out nor replace the intervals.
     */
    public static bool $syncingIntervals = false;

    /** Reason stored on intervals voided by a direct edit (set by GeofencedAttendanceService::recordHrCorrection()). */
    public static ?string $pendingIntervalReason = null;

    protected function casts(): array
    {
        return [
            'attendance_date'        => 'date',
            'scheduled_start'        => 'datetime',
            'scheduled_end'          => 'datetime',
            'check_in'               => 'datetime',
            'check_out'              => 'datetime',
            'worked_hours'           => 'decimal:4',
            'overtime_hours'         => 'decimal:4',
            'late_minutes'           => 'integer',
            'early_departure_minutes'=> 'integer',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function checkInVerification(): BelongsTo
    {
        return $this->belongsTo(AttendanceVerification::class, 'check_in_verification_id');
    }

    public function checkOutVerification(): BelongsTo
    {
        return $this->belongsTo(AttendanceVerification::class, 'check_out_verification_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(AttendanceVerification::class, 'attendance_record_id');
    }

    /** Every interval of the day, voided ones included (use ->active() for the live ones). */
    public function intervals(): HasMany
    {
        return $this->hasMany(AttendanceInterval::class, 'attendance_record_id')->orderBy('check_in');
    }

    protected static function booted(): void
    {
        // GPS / self-service times are evidence-backed. However they get
        // edited later (Filament edit, an approved time-change request,
        // tinker), the change must leave an audit row -- never a silent edit.
        static::updated(function (self $record): void {
            if (self::$suppressCorrectionAudit
                || ! in_array($record->source, AttendanceSource::evidenceBacked(), true)
                || ! $record->wasChanged(['check_in', 'check_out'])) {
                return;
            }

            $keys = ['check_in', 'check_out'];
            $before = [];
            $after = [];
            foreach ($keys as $key) {
                $before[$key] = $record->getOriginal($key) !== null ? Carbon::parse($record->getOriginal($key))->toDateTimeString() : null;
                $after[$key] = $record->{$key}?->toDateTimeString();
            }

            AttendanceVerification::query()->create([
                'company_id'           => $record->company_id,
                'employee_id'          => $record->employee_id,
                'user_id'              => Auth::id(),
                'attendance_record_id' => $record->id,
                'action'               => AttendanceVerificationAction::HrCorrection,
                'method'               => AttendanceVerificationMethod::Manual,
                'result'               => AttendanceVerificationResult::Overridden,
                'accepted'             => true,
                'metadata'             => [
                    'before'           => $before,
                    'after'            => $after,
                    'reason'           => null,
                    'path'             => 'direct_model_update',
                    'intervals_before' => AttendanceInterval::query()->where('attendance_record_id', $record->id)->active()->orderBy('check_in')->get(['check_in', 'check_out'])
                        ->map(fn (AttendanceInterval $interval): array => ['check_in' => $interval->check_in?->toDateTimeString(), 'check_out' => $interval->check_out?->toDateTimeString()])->all(),
                ],
                'server_recorded_at'   => now(),
            ]);

            // Query-builder update: deliberately does not re-fire model events.
            $record->newQuery()->whereKey($record->id)->update(['verification_status' => AttendanceVerificationStatus::Overridden->value]);
        });

        // Deleting an evidence-backed day must not erase the fact that it existed.
        // The verification rows survive the delete (their link is nulled by the
        // FK), and this row records who deleted what, when, and why.
        static::deleting(function (self $record): void {
            if (! in_array($record->source, AttendanceSource::evidenceBacked(), true)) {
                return;
            }

            AttendanceVerification::query()->create([
                'company_id'         => $record->company_id,
                'employee_id'        => $record->employee_id,
                'user_id'            => Auth::id(),
                'action'             => AttendanceVerificationAction::RecordDeleted,
                'method'             => AttendanceVerificationMethod::Manual,
                'result'             => AttendanceVerificationResult::Overridden,
                'accepted'           => true,
                'metadata'           => [
                    'path'           => 'record_deleted',
                    'reason'         => self::$pendingDeletionReason,
                    'deleted_record' => [
                        'id'                  => $record->id,
                        'attendance_date'     => $record->attendance_date?->toDateString(),
                        'check_in'            => $record->check_in?->toDateTimeString(),
                        'check_out'           => $record->check_out?->toDateTimeString(),
                        'status'              => $record->status,
                        'source'              => $record->source,
                        'verification_status' => $record->verification_status,
                    ],
                ],
                'server_recorded_at' => now(),
            ]);
        });

        static::creating(function (self $record): void {
            $record->creator_id ??= Auth::id();
            $record->company_id ??= $record->employee?->company_id;
        });

        static::created(function (self $record): void {
            if (self::$suppressCorrectionAudit) {
                return;
            }

            $date = $record->attendance_date?->toDateString();
            if (! $date) {
                return;
            }

            // attendance_date is the employee's LOCAL date while server_recorded_at is
            // stored in the app timezone, so match on the local day's time window.
            $timezone = $record->employee
                ? app(AttendanceScheduleResolver::class)->timezoneFor($record->employee)
                : config('app.timezone');
            $dayStart = Carbon::parse($date, $timezone)->startOfDay();
            $appTimezone = config('app.timezone');

            $rejected = AttendanceVerification::query()
                ->where('employee_id', $record->employee_id)
                ->where('accepted', false)
                ->where('method', AttendanceVerificationMethod::Gps)
                ->whereBetween('server_recorded_at', [
                    $dayStart->copy()->setTimezone($appTimezone),
                    $dayStart->copy()->endOfDay()->setTimezone($appTimezone),
                ])
                ->get();

            if ($rejected->isNotEmpty()) {
                foreach ($rejected as $verif) {
                    if ($verif->attendance_record_id === null) {
                        $verif->update(['attendance_record_id' => $record->id]);
                    }
                }

                AttendanceVerification::query()->create([
                    'company_id'           => $record->company_id,
                    'employee_id'          => $record->employee_id,
                    'user_id'              => Auth::id() ?? $record->creator_id,
                    'attendance_record_id' => $record->id,
                    'action'               => AttendanceVerificationAction::HrCorrection,
                    'method'               => AttendanceVerificationMethod::Manual,
                    'result'               => AttendanceVerificationResult::Overridden,
                    'accepted'             => true,
                    'metadata'             => [
                        'before'                  => null,
                        'after'                   => [
                            'check_in'  => $record->check_in?->toDateTimeString(),
                            'check_out' => $record->check_out?->toDateTimeString(),
                        ],
                        'reason'                  => $record->notes ?? 'Manual creation following rejected GPS attempt',
                        'path'                    => 'hr_manual_create_after_rejection',
                        'linked_verification_ids' => $rejected->pluck('id')->all(),
                    ],
                    'server_recorded_at'   => now(),
                ]);

                $record->newQuery()->whereKey($record->id)->update([
                    'verification_status' => AttendanceVerificationStatus::Overridden->value,
                ]);
            }
        });

        // Intervals follow every direct edit of the day's check-in / check-out
        // (Filament edit, HR correction, approved time-change request, import):
        // see AttendanceIntervalService::applyDirectEdit().
        static::created(function (self $record): void {
            if (! self::$syncingIntervals && $record->check_in !== null) {
                app(AttendanceIntervalService::class)->applyDirectEdit($record, true, self::$pendingIntervalReason);
            }
        });

        static::updated(function (self $record): void {
            if (! self::$syncingIntervals && $record->wasChanged(['check_in', 'check_out'])) {
                app(AttendanceIntervalService::class)->applyDirectEdit($record, false, self::$pendingIntervalReason);
            }
        });

        // The day's first check-in (any source): queue the employee's late check-in
        // notice (EmployeeAttendanceNotifier decides whether it was late and recent).
        static::created(function (self $record): void {
            if ($record->check_in !== null) {
                app(EmployeeAttendanceNotifier::class)->queueLateCheckIn($record);
            }
        });

        static::updated(function (self $record): void {
            if ($record->check_in !== null && $record->wasChanged('check_in') && $record->getOriginal('check_in') === null) {
                app(EmployeeAttendanceNotifier::class)->queueLateCheckIn($record);
            }
        });

        static::saving(function (self $record): void {
            // worked_hours is the sum of the intervals (written by AttendanceIntervalService);
            // a direct edit of the pair is one interval, so its span is that sum.
            if (! self::$syncingIntervals && $record->check_in && $record->check_out
                && (! $record->exists || $record->isDirty(['check_in', 'check_out']))) {
                $record->worked_hours = max(0, $record->check_in->diffInMinutes($record->check_out) / 60);
            }
            if ($record->scheduled_start && $record->check_in) {
                $record->late_minutes = max(0, $record->scheduled_start->diffInMinutes($record->check_in, false));
            }
            if ($record->scheduled_end && $record->check_out) {
                $record->early_departure_minutes = max(0, $record->check_out->diffInMinutes($record->scheduled_end, false));
            }
        });
    }
}
