<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Services\Drive\DriveInvoicePostingService;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;

/**
 * Phase 2 of Drive -> Aureus ingestion: one row per DriveIngestion that
 * reached Registered, capturing the minimal filename/metadata-based
 * classification DriveClassificationService derived from it, whatever it
 * resolved (partner/FS Tag/GL account), and the resulting validation
 * state.
 *
 * Phase 3 (see DriveInvoicePostingService) adds created_invoice_id/
 * posted_at/posting_failure_reason and the synchronizeApprovalState()
 * implementation below: once this classification's ApprovalRequest is
 * decided, the actual invoice/bill gets created and posted (or the
 * failure recorded) through the real accounts posting architecture --
 * never a hand-rolled second posting engine.
 */
class DriveIngestionClassification extends Model
{
    protected $table = 'accounting_drive_ingestion_classifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document_type'      => DriveDocumentType::class,
            'validation_status'  => DriveClassificationStatus::class,
            'validation_issues'  => 'array',
            'extracted_amount'   => 'decimal:4',
            'extracted_date'     => 'date',
            'posted_at'          => 'datetime',
        ];
    }

    public function driveIngestion(): BelongsTo
    {
        return $this->belongsTo(DriveIngestion::class);
    }

    public function createdInvoice(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'created_invoice_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function resolvedPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'resolved_partner_id');
    }

    public function resolvedFsTag(): BelongsTo
    {
        return $this->belongsTo(FsTag::class, 'resolved_fs_tag_id');
    }

    public function resolvedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'resolved_account_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * ApprovalSubjectSynchronizer contract: called by ApprovalEngine
     * (inside its own decision transaction -- see ApprovalEngine::decide())
     * after every decision recorded on this classification's approval
     * request, including a mid-workflow step that leaves the request still
     * pending. DriveInvoicePostingService::handleDecision() ignores
     * anything that isn't a terminal 'approved'/'rejected' status and is
     * itself idempotent (see its class doc), so it's safe to just forward
     * every call here unconditionally.
     */
    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        app(DriveInvoicePostingService::class)->handleDecision($this, $request);
    }
}
