<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What DriveClassificationService's minimal filename/metadata heuristic
 * believes a Drive-ingested Document represents. Unknown is the honest
 * default -- there is no real document-intelligence behind this yet, so a
 * file that doesn't match a recognized filename pattern stays Unknown
 * rather than being guessed into a specific type.
 */
enum DriveDocumentType: string implements HasColor, HasLabel
{
    case CustomerInvoice = 'customer_invoice';

    case VendorBill = 'vendor_bill';

    case CreditNote = 'credit_note';

    case DebitNote = 'debit_note';

    case Payment = 'payment';

    case BankStatement = 'bank_statement';

    case GeneralDocument = 'general_document';

    case Unknown = 'unknown';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CustomerInvoice => 'Customer Invoice',
            self::VendorBill      => 'Vendor Bill',
            self::CreditNote      => 'Credit Note',
            self::DebitNote       => 'Debit Note',
            self::Payment         => 'Payment',
            self::BankStatement   => 'Bank Statement',
            self::GeneralDocument => 'General Document',
            self::Unknown         => 'Unknown',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CustomerInvoice             => 'success',
            self::VendorBill                  => 'info',
            self::CreditNote, self::DebitNote => 'warning',
            self::Payment                     => 'success',
            self::BankStatement               => 'info',
            self::GeneralDocument             => 'gray',
            self::Unknown                     => 'danger',
        };
    }

    /**
     * Whether this type is a real invoice/bill type that Phase 2 should
     * ever route toward approval. Everything else (GeneralDocument,
     * BankStatement, Payment, Unknown) stops at NeedsReview -- there is
     * no invoice/bill to validate or approve.
     */
    public function isInvoiceLike(): bool
    {
        return in_array($this, [self::CustomerInvoice, self::VendorBill, self::CreditNote, self::DebitNote], true);
    }
}
