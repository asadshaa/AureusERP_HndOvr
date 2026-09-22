<?php

namespace Webkul\Accounting\Services\Drive;

use Illuminate\Support\Facades\Storage;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Tax;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Phase 2 of Drive -> Aureus ingestion: classification, validation and
 * approval routing for a Registered DriveIngestion. Deliberately stops
 * short of invoice/bill creation, GL posting, or journal creation -- see
 * routeForApproval()'s doc for the exact hook point a later phase picks
 * up from.
 */
class DriveClassificationService
{
    public function __construct(
        private readonly FsTagService $fsTags,
        private readonly ApprovalEngine $approvals,
        private readonly ?PdfInvoiceTextExtractor $pdfExtractor = null,
    ) {}

    public function classify(DriveIngestion $ingestion): DriveIngestionClassification
    {
        $classification = DriveIngestionClassification::query()->firstOrNew(
            ['drive_ingestion_id' => $ingestion->id],
            ['company_id' => $ingestion->company_id],
        );

        $extracted = $this->extractFromFilename($ingestion->filename);
        $issues = [];
        $taxRate = null;

        // Overlay PDF candidate extraction if linked Document exists with bytes in storage
        if ($ingestion->document && $ingestion->document->currentVersion) {
            $version = $ingestion->document->currentVersion;
            $disk = Storage::disk($version->storage_disk ?: 'accounting_documents');
            $path = $version->storage_path;
            if ($path && $disk->exists($path)) {
                $content = $disk->get($path);
                $isPdf = str_ends_with(strtolower($ingestion->filename), '.pdf') ||
                         str_contains(strtolower($ingestion->mime_type ?? ''), 'pdf');

                if ($isPdf) {
                    $extractor = $this->pdfExtractor ?? new PdfInvoiceTextExtractor;
                    $rounding = (float) ($ingestion->company?->currency?->rounding ?? 0.01);
                    $pdfData = $extractor->extract($content, $rounding);

                    if ($pdfData['is_malformed']) {
                        $issues[] = 'PDF binary is malformed or corrupted.';
                    } elseif ($pdfData['is_scanned']) {
                        if (! $extracted['amount'] || ! $extracted['partner_name'] || ! $extracted['invoice_number']) {
                            $issues[] = 'Scanned PDF with no extractable text. Requires manual entry.';
                        }
                    } else {
                        if ($pdfData['math_balanced'] === false) {
                            $issues[] = 'Extracted line items or subtotals do not mathematically balance with the grand total.';
                        }

                        if ($pdfData['document_type_candidate'] && $extracted['document_type'] === DriveDocumentType::Unknown) {
                            $extracted['document_type'] = match ($pdfData['document_type_candidate']) {
                                'customer_invoice' => DriveDocumentType::CustomerInvoice,
                                'vendor_bill'      => DriveDocumentType::VendorBill,
                                'credit_note'      => DriveDocumentType::CreditNote,
                                'debit_note'       => DriveDocumentType::DebitNote,
                                default            => DriveDocumentType::Unknown,
                            };
                        }

                        if ($pdfData['invoice_number'] && ! $extracted['invoice_number']) {
                            $extracted['invoice_number'] = $pdfData['invoice_number'];
                        }

                        if ($pdfData['partner_name'] && ! $extracted['partner_name']) {
                            $extracted['partner_name'] = $pdfData['partner_name'];
                        }

                        if ($pdfData['total_amount'] !== null && $extracted['amount'] === null) {
                            $extracted['amount'] = (string) $pdfData['total_amount'];
                        }

                        if ($pdfData['currency_code'] && ! $extracted['currency_code']) {
                            $extracted['currency_code'] = $pdfData['currency_code'];
                        }

                        if ($pdfData['invoice_date'] && ! $extracted['date']) {
                            $extracted['date'] = $pdfData['invoice_date'];
                        }

                        if (! empty($pdfData['fs_tag_code']) && ! $extracted['fs_tag_code']) {
                            $extracted['fs_tag_code'] = $pdfData['fs_tag_code'];
                        }

                        $taxRate = $pdfData['tax_rate'];
                    }
                }
            }
        }

        $classification->fill([
            'company_id'                => $ingestion->company_id,
            'document_type'             => $extracted['document_type']->value,
            'extracted_invoice_number'  => $extracted['invoice_number'],
            'extracted_partner_name'    => $extracted['partner_name'],
            'extracted_amount'          => $extracted['amount'],
            'extracted_currency_code'   => $extracted['currency_code'],
            'extracted_date'            => $extracted['date'],
            'extracted_fs_tag_code'     => $extracted['fs_tag_code'],
            'validation_status'         => DriveClassificationStatus::PendingReview,
            'validation_issues'         => null,
            'resolved_partner_id'       => null,
            'resolved_fs_tag_id'        => null,
            'resolved_account_id'       => null,
        ]);
        $classification->save();

        if ($extracted['document_type'] === DriveDocumentType::Unknown) {
            $issues[] = 'Could not determine the document type from the filename -- it does not match any recognized invoice/bill/credit-note/debit-note naming pattern.';
        }

        if ($extracted['document_type'] === DriveDocumentType::DebitNote) {
            $issues[] = 'Debit Notes require manual review and cannot be automatically posted.';
        }

        $isInvoiceLike = $extracted['document_type']->isInvoiceLike();

        if ($isInvoiceLike) {
            if ($extracted['invoice_number'] === null || trim((string) $extracted['invoice_number']) === '') {
                $issues[] = 'No invoice number could be extracted from the document.';
            }

            if ($extracted['amount'] === null || trim((string) $extracted['amount']) === '') {
                $issues[] = 'No amount could be extracted from the document.';
            }

            if ($extracted['currency_code'] === null || trim((string) $extracted['currency_code']) === '') {
                $issues[] = 'No currency code could be extracted from the document.';
            }
        }

        $partner = $this->resolvePartner($ingestion->company_id, $extracted['partner_name'], $issues, $isInvoiceLike, $extracted['document_type']);
        $fsTag = $this->resolveFsTag($ingestion->company_id, $extracted['fs_tag_code'], $issues, $isInvoiceLike);
        $account = $this->resolveAccount($fsTag, $ingestion->company_id, $issues, $isInvoiceLike);
        $this->validateExtractedDate($ingestion, $extracted, $issues, $isInvoiceLike);

        if ($taxRate !== null) {
            $this->validateCandidateTax($ingestion->company_id, $taxRate, $extracted['document_type'], $issues);
        }

        $classification->resolved_partner_id = $partner?->id;
        $classification->resolved_fs_tag_id = $fsTag?->id;
        $classification->resolved_account_id = $account?->id;

        if (! $isInvoiceLike && $extracted['document_type'] !== DriveDocumentType::Unknown) {
            // A recognized but non-invoice type (BankStatement, Payment,
            // GeneralDocument) is never routed for approval in Phase 2 --
            // there's nothing here for that flow to validate or approve.
            $issues[] = "Document type \"{$extracted['document_type']->getLabel()}\" is not an invoice/bill type handled by this review queue.";
        }

        $duplicate = null;
        if ($extracted['document_type']->isInvoiceLike() && $partner && $extracted['amount'] !== null) {
            $duplicate = $this->detectDuplicate(
                $ingestion->company_id,
                $extracted['document_type'],
                $extracted['invoice_number'],
                $partner->id,
                $extracted['amount'],
                $extracted['currency_code'],
            );
        }

        if ($duplicate) {
            $issues[] = "Possible duplicate of existing move #{$duplicate->id} (\"{$duplicate->name}\") -- same company, partner, amount and currency.";
            $classification->validation_status = DriveClassificationStatus::DuplicateSuspected;
            $classification->validation_issues = $issues;
            $classification->save();

            return $classification;
        }

        if ($issues !== []) {
            $classification->validation_status = DriveClassificationStatus::NeedsReview;
            $classification->validation_issues = $issues;
            $classification->save();

            return $classification;
        }

        $classification->validation_status = DriveClassificationStatus::Valid;
        $classification->validation_issues = null;
        $classification->save();

        $this->routeForApproval($classification);

        return $classification->refresh();
    }

    /**
     * Minimal filename-based extraction. Patterns recognized:
     *   INV-<number>...   -> CustomerInvoice
     *   BILL-<number>...  -> VendorBill
     *   CN-<number>...    -> CreditNote
     *   DN-<number>...    -> DebitNote
     * Optional trailing segments separated by underscores are read as
     * partner name, amount+currency (e.g. "1250.00USD") and an FS Tag
     * code (a segment starting with "FS-" or "FS"), in any order after
     * the leading type/number segment. Anything not present is left
     * null -- never guessed.
     *
     * @return array{document_type: DriveDocumentType, invoice_number: ?string, partner_name: ?string, amount: ?string, currency_code: ?string, date: ?string, fs_tag_code: ?string}
     */
    private function extractFromFilename(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $segments = preg_split('/[_\s]+/', $base) ?: [];

        $documentType = DriveDocumentType::Unknown;
        $invoiceNumber = null;

        if (preg_match('/^INV-([^_]+)/i', $base, $m)) {
            $documentType = DriveDocumentType::CustomerInvoice;
            $invoiceNumber = $m[1];
        } elseif (preg_match('/^BILL-([^_]+)/i', $base, $m)) {
            $documentType = DriveDocumentType::VendorBill;
            $invoiceNumber = $m[1];
        } elseif (preg_match('/^CN-([^_]+)/i', $base, $m)) {
            $documentType = DriveDocumentType::CreditNote;
            $invoiceNumber = $m[1];
        } elseif (preg_match('/^DN-([^_]+)/i', $base, $m)) {
            $documentType = DriveDocumentType::DebitNote;
            $invoiceNumber = $m[1];
        } elseif (preg_match('/^(STMT|STATEMENT)-/i', $base)) {
            $documentType = DriveDocumentType::BankStatement;
        } elseif (preg_match('/^PAY(MENT)?-/i', $base)) {
            $documentType = DriveDocumentType::Payment;
        }

        $partnerName = null;
        $amount = null;
        $currencyCode = null;
        $fsTagCode = null;
        $date = null;

        foreach (array_slice($segments, 1) as $segment) {
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([A-Za-z]{3})$/', $segment, $m)) {
                $amount = $m[1];
                $currencyCode = strtoupper($m[2]);

                continue;
            }

            if (preg_match('/^FS-?\w+$/i', $segment)) {
                $fsTagCode = $segment;

                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $segment)) {
                $date = $segment;

                continue;
            }

            if ($partnerName === null && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $segment)) {
                $partnerName = $segment;
            }
        }

        return [
            'document_type'  => $documentType,
            'invoice_number' => $invoiceNumber,
            'partner_name'   => $partnerName,
            'amount'         => $amount,
            'currency_code'  => $currencyCode,
            'date'           => $date,
            'fs_tag_code'    => $fsTagCode,
        ];
    }

    /** @param array<int, string> $issues */
    private function resolvePartner(
        int $companyId,
        ?string $partnerName,
        array &$issues,
        bool $required,
        DriveDocumentType $documentType = DriveDocumentType::Unknown
    ): ?Partner {
        if ($partnerName === null || trim($partnerName) === '') {
            if ($required) {
                $issues[] = 'No partner name could be extracted from the filename.';
            }

            return null;
        }

        $matches = Partner::query()
            ->where('company_id', $companyId)
            ->where('name', 'like', "%{$partnerName}%")
            ->get();

        if ($matches->count() === 0) {
            $issues[] = "No partner matches \"{$partnerName}\" for this company.";

            return null;
        }

        if ($matches->count() > 1) {
            $issues[] = "Multiple partners match \"{$partnerName}\" for this company -- resolve manually.";

            return null;
        }

        $partner = $matches->first();

        if ($documentType === DriveDocumentType::CreditNote) {
            $custRank = (int) ($partner->customer_rank ?? 0);
            $suppRank = (int) ($partner->supplier_rank ?? 0);

            if ($custRank > 0 && $suppRank > 0) {
                $issues[] = "Partner \"{$partner->name}\" has both Customer and Vendor roles. Partner role is ambiguous for Credit Note -- resolve manually.";
            } elseif ($custRank <= 0 && $suppRank <= 0) {
                $issues[] = "Partner \"{$partner->name}\" has neither Customer nor Vendor role. Partner role is ambiguous for Credit Note -- resolve manually.";
            }
        }

        return $partner;
    }

    /** @param array<int, string> $issues */
    private function resolveFsTag(int $companyId, ?string $code, array &$issues, bool $required): ?FsTag
    {
        if ($code === null || trim($code) === '') {
            if ($required) {
                $issues[] = 'No FS Tag code could be extracted from the filename.';
            }

            return null;
        }

        $tag = $this->fsTags->resolve($companyId, $code);
        if ($tag) {
            return $tag;
        }

        $diagnosis = $this->fsTags->diagnose($companyId, $code);
        if ($diagnosis) {
            $issues[] = $diagnosis;
        }

        return null;
    }

    /** @param array<int, string> $issues */
    private function resolveAccount(?FsTag $fsTag, int $companyId, array &$issues, bool $required): ?Account
    {
        if (! $fsTag || ! $fsTag->account_id) {
            if ($required && $fsTag && ! $fsTag->account_id) {
                $issues[] = "FS Tag \"{$fsTag->code}\" has no GL account mapped.";
            }

            return null;
        }

        $account = Account::query()
            ->postable()
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->find($fsTag->account_id);

        if (! $account) {
            $issues[] = "FS Tag \"{$fsTag->code}\" is mapped to a GL account that is unknown, inactive, non-postable, or not owned by this company.";

            return null;
        }

        return $account;
    }

    /** @param array<int, string> $issues */
    private function validateCandidateTax(int $companyId, float $taxRate, DriveDocumentType $documentType, array &$issues): void
    {
        $useType = match ($documentType) {
            DriveDocumentType::CustomerInvoice => TypeTaxUse::SALE,
            DriveDocumentType::VendorBill      => TypeTaxUse::PURCHASE,
            default                            => null,
        };

        $query = Tax::query()
            ->forCompany($companyId)
            ->active()
            ->where('amount', $taxRate);

        if ($useType) {
            $query->where('type_tax_use', $useType);
        }

        $matches = $query->get();

        if ($matches->count() === 0) {
            $issues[] = "No active tax found for rate {$taxRate}% in this company.";
        } elseif ($matches->count() > 1) {
            $issues[] = "Multiple active taxes match rate {$taxRate}% for this company -- resolve manually.";
        }
    }

    /**
     * extracted_date is pure filename-heuristic output (see
     * extractFromFilename()) and, unlike partner/FS Tag/account, most
     * documents don't need it to reach Valid at all. But when the
     * document's currency differs from the company's, whatever ends up on
     * Move::invoice_date is exactly what later determines which historical
     * FX rate gets used to post it (Move::computeInvoiceCurrencyRate()
     * looks the rate up as of invoice_date) -- so for that specific case a
     * missing date is a real financial-correctness problem, not a
     * cosmetic one, and must block Valid exactly like the other required
     * fields do.
     *
     * @param  array{document_type: DriveDocumentType, invoice_number: ?string, partner_name: ?string, amount: ?string, currency_code: ?string, date: ?string, fs_tag_code: ?string}  $extracted
     * @param  array<int, string>  $issues
     */
    private function validateExtractedDate(DriveIngestion $ingestion, array $extracted, array &$issues, bool $required): void
    {
        if (! $required || $extracted['date'] !== null || $extracted['currency_code'] === null) {
            // No currency was extracted at all: resolveCurrency() at
            // posting time (DriveInvoicePostingService) already refuses to
            // guess a currency and blocks posting outright, so there is no
            // silent-FX-rate risk left to warn about here.
            return;
        }

        $companyCurrencyCode = $ingestion->company?->currency?->code;

        if ($companyCurrencyCode !== null && strtoupper($companyCurrencyCode) === strtoupper($extracted['currency_code'])) {
            // Same currency as the company: Currency::getConversionRate()
            // short-circuits to a rate of 1 whenever the two currency ids
            // match, regardless of date -- no historical-rate risk.
            return;
        }

        $issues[] = "No date could be extracted from the filename, and the document's currency ({$extracted['currency_code']}) differs from the company currency -- posting would silently use today's date (and today's exchange rate) instead of the document's actual date.";
    }

    private function detectDuplicate(
        int $companyId,
        DriveDocumentType $documentType,
        ?string $invoiceNumber,
        int $partnerId,
        string $amount,
        ?string $currencyCode,
    ): ?Move {
        if ($invoiceNumber === null) {
            return null;
        }

        $moveTypes = match ($documentType) {
            DriveDocumentType::CustomerInvoice => [MoveType::OUT_INVOICE],
            DriveDocumentType::VendorBill      => [MoveType::IN_INVOICE],
            DriveDocumentType::CreditNote      => [MoveType::OUT_REFUND, MoveType::IN_REFUND],
            DriveDocumentType::DebitNote       => [MoveType::IN_INVOICE, MoveType::OUT_INVOICE],
            default                            => [],
        };

        if ($moveTypes === []) {
            return null;
        }

        $currencyId = $currencyCode
            ? Currency::query()->where('code', $currencyCode)->value('id')
            : null;

        return Move::query()
            ->where('company_id', $companyId)
            ->whereIn('move_type', array_map(fn (MoveType $type) => $type->value, $moveTypes))
            ->where('partner_id', $partnerId)
            ->where(function ($query) use ($invoiceNumber) {
                $query->where('reference', $invoiceNumber)
                    ->orWhere('payment_reference', $invoiceNumber)
                    ->orWhere('reference', 'INV-'.$invoiceNumber)
                    ->orWhere('reference', 'BILL-'.$invoiceNumber)
                    ->orWhere('name', $invoiceNumber)
                    ->orWhere('name', 'INV-'.$invoiceNumber);
            })
            ->when($currencyId, fn ($query) => $query->where('currency_id', $currencyId))
            ->whereBetween('amount_total', [
                bcsub($amount, '0.01', 4),
                bcadd($amount, '0.01', 4),
            ])
            ->first();
    }

    /**
     * Creates an ApprovalRequest for $classification via the real
     * ApprovalEngine -- same pattern as
     * ExchangeRateApprovalService::submit(). There is no dedicated
     * system/bot actor anywhere in this codebase (automated audit rows
     * elsewhere just use actor_id null), but ApprovalEngine::submit()
     * requires a real, company-authorized User for its hierarchy/company
     * access checks, so the automated submission here is attributed to
     * the first active user scoped to the classification's company. If
     * no active approval workflow is configured for
     * 'drive_ingestion_classification' in this company, submission fails
     * loudly (ApprovalEngine throws) rather than silently skipping
     * routing -- the classification stays Valid with no
     * approval_request_id, which is visible in the review resource.
     */
    private function routeForApproval(DriveIngestionClassification $classification): void
    {
        $requester = User::query()
            ->where('default_company_id', $classification->company_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $requester) {
            return;
        }

        if (! $this->approvals->matchingWorkflow($classification->company_id, 'drive_ingestion_classification')) {
            return;
        }

        $context = [
            'company_id'     => $classification->company_id,
            'document_type'  => $classification->document_type?->value,
            'invoice_number' => $classification->extracted_invoice_number,
            'partner_id'     => $classification->resolved_partner_id,
            'amount'         => (string) $classification->extracted_amount,
            'currency_code'  => $classification->extracted_currency_code,
            'extracted_date' => $classification->extracted_date?->toDateString(),
        ];

        $existing = ApprovalRequest::query()
            ->where('company_id', $classification->company_id)
            ->where('request_type', 'drive_ingestion_classification')
            ->where('subject_type', $classification->getMorphClass())
            ->where('subject_id', $classification->getKey())
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'amount'  => (string) $classification->extracted_amount,
                'context' => $context,
            ]);
            $classification->update(['approval_request_id' => $existing->id]);

            return;
        }

        $request = $this->approvals->submit(
            $classification,
            $requester,
            'drive_ingestion_classification',
            (string) $classification->extracted_amount,
            $context,
        );

        $classification->update(['approval_request_id' => $request->id]);
    }
}
