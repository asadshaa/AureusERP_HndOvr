<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Employee\Enums\PunchPairingMode;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One uploaded biometric / device attendance file (Attendance -> Device
 * import). Written only by DeviceAttendanceImportService.
 */
class AttendanceImportBatch extends Model
{
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_PARTIALLY_UNDONE = 'partially_undone';

    public const STATUS_UNDONE = 'undone';

    /** Batches whose live punches an undo can still remove. */
    public const UNDOABLE_STATUSES = [self::STATUS_IMPORTED, self::STATUS_PARTIALLY_UNDONE, self::STATUS_IMPORTING];

    protected $table = 'employees_attendance_import_batches';

    protected $fillable = [
        'company_id',
        'file_name',
        'file_path',
        'file_hash',
        'file_format',
        'date_order',
        'pairing_mode',
        'timezone',
        'status',
        'total_rows',
        'imported_rows',
        'duplicate_rows',
        'problem_rows',
        'days_written',
        'review_days',
        'flagged_days',
        'summary',
        'undo_summary',
        'created_by',
        'imported_by',
        'imported_at',
        'undone_by',
        'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'summary'        => 'array',
            'undo_summary'   => 'array',
            'imported_at'    => 'datetime',
            'undone_at'      => 'datetime',
            'total_rows'     => 'integer',
            'imported_rows'  => 'integer',
            'duplicate_rows' => 'integer',
            'problem_rows'   => 'integer',
            'days_written'   => 'integer',
            'review_days'    => 'integer',
            'flagged_days'   => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AttendanceImportRow::class, 'batch_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(AttendanceImportDay::class, 'batch_id');
    }

    public function pairingMode(): PunchPairingMode
    {
        return PunchPairingMode::tryFrom((string) $this->pairing_mode) ?? PunchPairingMode::FirstInLastOut;
    }

    public function canBeUndone(): bool
    {
        return in_array($this->status, self::UNDOABLE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PREVIEW          => 'Preview (not imported)',
            self::STATUS_IMPORTING        => 'Importing',
            self::STATUS_IMPORTED         => 'Imported',
            self::STATUS_PARTIALLY_UNDONE => 'Partly undone',
            self::STATUS_UNDONE           => 'Undone',
            default                       => ucfirst((string) $this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_IMPORTED         => 'success',
            self::STATUS_PREVIEW          => 'info',
            self::STATUS_IMPORTING        => 'warning',
            self::STATUS_PARTIALLY_UNDONE => 'warning',
            default                       => 'gray',
        };
    }
}
