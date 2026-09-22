<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * State of a DriveIngestionClassification row.
 *
 *   PendingReview --[classify() runs]--> Valid | NeedsReview | DuplicateSuspected | Rejected
 *   Valid --[ApprovalRequest approved]--> Posted | PostingFailed
 *   Valid --[ApprovalRequest rejected]--> Rejected
 *
 * NeedsReview and DuplicateSuspected are terminal for Phase 2 -- a human
 * resolves them through the review resource; nothing here auto-retries.
 * Posted and PostingFailed are Phase 3 additions (see
 * DriveInvoicePostingService): a Valid classification whose approval
 * request is later Approved moves to Posted on success or PostingFailed on
 * any failure while creating/posting the invoice -- never silently left at
 * Valid, and never repurposing NeedsReview (which means something
 * different: the classification itself needs human review, not that
 * posting blew up after approval).
 */
enum DriveClassificationStatus: string implements HasColor, HasLabel
{
    case PendingReview = 'pending_review';

    case Valid = 'valid';

    case NeedsReview = 'needs_review';

    case Rejected = 'rejected';

    case DuplicateSuspected = 'duplicate_suspected';

    case Posted = 'posted';

    case PostingFailed = 'posting_failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PendingReview      => 'Pending Review',
            self::Valid              => 'Valid',
            self::NeedsReview        => 'Needs Review',
            self::Rejected           => 'Rejected',
            self::DuplicateSuspected => 'Duplicate Suspected',
            self::Posted             => 'Posted',
            self::PostingFailed      => 'Posting Failed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PendingReview      => 'gray',
            self::Valid              => 'success',
            self::NeedsReview        => 'warning',
            self::Rejected           => 'danger',
            self::DuplicateSuspected => 'danger',
            self::Posted             => 'success',
            self::PostingFailed      => 'danger',
        };
    }
}
