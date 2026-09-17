<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Database\Factories\DocumentFactory;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    protected $table = 'accounting_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status'        => DocumentStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DocumentAudit::class)->latest();
    }

    /**
     * Present only once this document has been exported to Drive at
     * least once -- absence of a row means "never synced", same meaning
     * as DriveSyncStatus::NotSynced would if the row existed. Callers
     * that need a status to display should fall back to NotSynced
     * rather than treating a missing row as an error.
     */
    public function driveSync(): HasOne
    {
        return $this->hasOne(DocumentDriveSync::class);
    }

    /**
     * Explicit, opt-in company scope -- mirrors Tax::scopeForCompany() from
     * the tax-picker hardening work. Never queried without this: there is
     * no global scope here on purpose, so every call site stays honest
     * about scoping to a company rather than relying on implicit magic.
     */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        if (! $companyId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->creator_id ??= Auth::id();
            $document->status ??= DocumentStatus::Active;
        });
    }
}
