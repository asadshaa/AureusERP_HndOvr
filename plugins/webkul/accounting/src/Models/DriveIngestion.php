<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Support\Models\Company;

/**
 * One row per Drive file ever discovered in a company's inbound folder --
 * the Drive -> Aureus mirror of DocumentDriveSync. Phase 1 only: this
 * record tracks discovery/dedup/download/registration, never invoice
 * creation, FS Tag resolution, GL posting, or approval routing.
 */
class DriveIngestion extends Model
{
    protected $table = 'accounting_drive_ingestions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'            => DriveIngestionStatus::class,
            'file_size'         => 'integer',
            'drive_modified_at' => 'datetime',
            'discovered_at'     => 'datetime',
            'processed_at'      => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Explicit, opt-in company scope -- same pattern as
     * FsTag::scopeForCompany()/Document::scopeForCompany(). Never queried
     * without this outside of a system process that already resolved the
     * company itself (e.g. discover() iterating one company at a time).
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
