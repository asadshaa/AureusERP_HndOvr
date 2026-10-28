<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One uploaded employee document. The file is on the private 'local' disk;
 * it is only ever handed out through temporaryDownloadUrl(), a signed and
 * short-lived link to a route that checks the viewer's permission again.
 *
 * @property CarbonImmutable|null $expires_on
 */
class EmployeeDocument extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    public const DIRECTORY = 'employees/documents';

    /** Where the upload field puts a file before EmployeeDocumentService files it under the employee. */
    public const INCOMING_DIRECTORY = 'employees/documents/incoming';

    public const MAX_SIZE_KB = 10240;

    /** @var array<int, string> */
    public const ACCEPTED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public const STATUS_VERIFIED = 'verified';

    /** Uploaded by the employee, waiting for HR to check it. */
    public const STATUS_PENDING = 'pending';

    /** Days before expiry at which an "expiring soon" badge shows. */
    public const EXPIRING_SOON_DAYS = 30;

    public const DOWNLOAD_LINK_MINUTES = 10;

    protected $table = 'employees_employee_documents';

    protected $fillable = [
        'company_id',
        'employee_id',
        'document_type_id',
        'title',
        'number',
        'issued_on',
        'expires_on',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'notes',
        'status',
        'uploaded_by',
        'verified_by',
        'verified_at',
        'replaced_by_id',
        'expiry_reminders',
    ];

    protected $casts = [
        'issued_on'        => 'immutable_date',
        'expires_on'       => 'immutable_date',
        'verified_at'      => 'datetime',
        'size'             => 'integer',
        'expiry_reminders' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id')->withTrashed();
    }

    public static function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** Days until expiry (negative once expired), or null without an expiry date. */
    public function daysToExpiry(?CarbonImmutable $today = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        $today ??= self::today();
        $expires = CarbonImmutable::parse($this->expires_on->toDateString(), self::timezone());

        return (int) $today->diffInDays($expires, false);
    }

    /** 'expired' | 'expiring' | 'valid', or null without an expiry date. */
    public function expiryState(?CarbonImmutable $today = null): ?string
    {
        $days = $this->daysToExpiry($today);

        return match (true) {
            $days === null                    => null,
            $days < 0                         => 'expired',
            $days <= self::EXPIRING_SOON_DAYS => 'expiring',
            default                           => 'valid',
        };
    }

    public static function expiryColor(?string $state): string
    {
        return match ($state) {
            'expired'  => 'danger',
            'expiring' => 'warning',
            'valid'    => 'success',
            default    => 'gray',
        };
    }

    public function expiryLabel(?CarbonImmutable $today = null): string
    {
        $days = $this->daysToExpiry($today);

        return match ($this->expiryState($today)) {
            'expired'  => 'Expired '.$this->expires_on->format('d M Y'),
            'expiring' => $days === 0 ? 'Expires today' : "Expires in {$days} day".($days === 1 ? '' : 's'),
            'valid'    => 'Valid until '.$this->expires_on->format('d M Y'),
            default    => 'No expiry',
        };
    }

    public function scopeExpired(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', ($today ?? self::today())->toDateString());
    }

    public function scopeExpiringWithin(Builder $query, int $days = self::EXPIRING_SOON_DAYS, ?CarbonImmutable $today = null): Builder
    {
        $today ??= self::today();

        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $today->addDays($days)->toDateString());
    }

    /** A signed link, valid for a few minutes, to the permission-checked download route. */
    public function temporaryDownloadUrl(int $minutes = self::DOWNLOAD_LINK_MINUTES): string
    {
        return URL::temporarySignedRoute('employees.documents.download', now()->addMinutes($minutes), ['document' => $this->getKey()]);
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->documentType?->name ?? 'Document');
    }

    protected static function booted(): void
    {
        // A new expiry date starts the reminders again.
        static::saving(function (self $document): void {
            if ($document->exists && $document->isDirty('expires_on')) {
                $document->expiry_reminders = null;
            }
        });
    }
}
