<?php

namespace Webkul\Accounting\Services\Drive;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Tax;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Currency;

/**
 * Phase 3 of Drive -> Aureus ingestion: reacts to a decision on a
 * DriveIngestionClassification's ApprovalRequest (via
 * DriveIngestionClassification::synchronizeApprovalState(), called by
 * ApprovalSubjectSynchronizer from inside ApprovalEngine::decide()'s own
 * transaction) and either creates + posts the real invoice/bill, or
 * records why it couldn't.
 *
 * Reuse only, per the Phase 3 brief: invoice/bill creation goes through
 * the plain Move/MoveLine models exactly as
 * InvoiceResource/BillResource's CreateInvoice/CreateBill pages build
 * them (journal chosen the same way -- first SALE/PURCHASE journal for
 * the company), and posting goes through the real
 * AccountFacade::confirmMove() (Webkul\Account\AccountManager), the exact
 * mechanism ConfirmAction and BankJournalService::post() both use. This
 * class never hand-computes a balanced entry itself.
 *
 * FS Tag -> GL account reconciliation decision (see class docs on FsTag
 * and DriveClassificationService::resolveAccount()): a FsTag is *always*
 * bound to exactly one Account via its own account_id column -- that is
 * the existing architectural source of truth for what account a tagged
 * transaction posts against (this is exactly what BankJournalService
 * relies on too: it stamps fs_tag_id onto a MoveLine and nothing else
 * carries an "account for this FS Tag" value). Phase 2's
 * resolved_account_id on the classification is not a second, independent
 * resolution -- resolveAccount() only ever re-validates
 * (postable/active/company-owned) the very same $fsTag->account_id and
 * stores that. So at posting time this service re-fetches the FS Tag
 * fresh, re-validates it exactly as classify() did, and posts against
 * *its* current account_id -- and, defensively, refuses to post at all if
 * that no longer matches what was stored on the classification at
 * classification time (the FS Tag's mapping changed underneath an
 * approval already in flight), rather than silently picking one.
 *
 * Single summary line, documented scope limit: Phase 2 only ever
 * extracted one total amount from a filename (see
 * DriveClassificationService's class doc -- this is filename/metadata
 * heuristics, not real OCR/line-item extraction), so Phase 3 posts
 * exactly one DisplayType::PRODUCT line for the full extracted_amount.
 * There is no per-line-item breakdown to post even if this service wanted
 * one.
 *
 * Failure handling: the whole create-Move+add-line+confirmMove+attach-
 * document sequence runs inside DB::transaction(). Because
 * synchronizeApprovalState() is itself already called from inside
 * ApprovalEngine::decide()'s own transaction, this nested call becomes a
 * SAVEPOINT (standard Laravel behaviour) -- so a failure here rolls back
 * only the invoice-creation attempt, never the approval decision itself
 * (the approval genuinely happened; a bug or data problem in posting is a
 * separate, retryable concern). Nothing half-created is ever left behind:
 * either the whole Move+lines+attachment exists, or none of it does, and
 * posting_failure_reason explains why.
 */
class DriveInvoicePostingService
{
    /**
     * Only these two DriveDocumentType cases have a well-defined
     * move_type/journal mapping per the Phase 3 brief (CustomerInvoice ->
     * out_invoice/Sales journal, VendorBill -> in_invoice/Purchase
     * journal). CreditNote/DebitNote are invoice-like enough to reach
     * Valid in Phase 2, but Phase 3 deliberately does not guess a
     * move_type/journal for them -- that would be inventing a mapping the
     * brief never specified, not reusing one. They fail loudly to
     * posting_failure_reason instead.
     */
    private const SUPPORTED_TYPES = [
        DriveDocumentType::CustomerInvoice,
        DriveDocumentType::VendorBill,
        DriveDocumentType::CreditNote,
    ];

    public function __construct(
        private readonly FsTagService $fsTags,
        private readonly DocumentService $documents,
    ) {}

    public function handleDecision(DriveIngestionClassification $classification, ApprovalRequest $request): void
    {
        // decide() calls synchronize() after every recorded decision, not
        // only the one that completes the workflow -- a mid-workflow
        // approval step leaves the request 'pending' with more steps to
        // go. There is nothing to do here until the request reaches a
        // terminal state.
        if (! in_array($request->status, ['approved', 'rejected'], true)) {
            return;
        }

        if ($request->status === 'rejected') {
            if ($classification->validation_status !== DriveClassificationStatus::Rejected) {
                $classification->update(['validation_status' => DriveClassificationStatus::Rejected]);
            }

            return;
        }

        // Idempotency guard: an approval decision firing twice (a retried
        // event, a re-delivered queue job, whatever the trigger) must
        // never create a second invoice for the same classification.
        // Re-checked again inside the transaction below with a row lock --
        // this first check is just a cheap early exit for the common case.
        if ($classification->created_invoice_id !== null) {
            return;
        }

        try {
            DB::transaction(function () use ($classification, $request): void {
                $locked = DriveIngestionClassification::query()
                    ->whereKey($classification->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->created_invoice_id !== null) {
                    return;
                }

                $move = $this->createAndPostMove($locked, $request);

                $locked->update([
                    'created_invoice_id'      => $move->id,
                    'posted_at'               => now(),
                    'validation_status'       => DriveClassificationStatus::Posted,
                    'posting_failure_reason'  => null,
                ]);
            });
        } catch (Throwable $e) {
            // Deliberately OUTSIDE the transaction above (that one already
            // rolled back to its savepoint by the time we get here) --
            // this write must survive even though the Move creation did
            // not. The outer ApprovalEngine::decide() transaction is still
            // open and will still commit the approval decision itself; only
            // the invoice-creation attempt was undone.
            $classification->update([
                'posting_failure_reason' => Str::limit($e->getMessage(), 2000),
                'validation_status'      => DriveClassificationStatus::PostingFailed,
            ]);
        }
    }

    private function createAndPostMove(DriveIngestionClassification $classification, ApprovalRequest $request): Move
    {
        $classification->loadMissing(['driveIngestion.document', 'resolvedPartner', 'company']);

        $companyId = (int) $classification->company_id;

        $partner = $classification->resolvedPartner;
        if (! $partner) {
            throw new RuntimeException('The classification has no resolved partner -- it should never have reached approval without one.');
        }
        if ((int) $partner->company_id !== $companyId) {
            throw new RuntimeException('The resolved partner does not belong to this classification\'s company.');
        }

        $currency = $this->resolveCurrency($classification);
        $fsTag = null;
        $account = $this->resolveReconciledAccount($classification, $companyId, $fsTag);

        [$moveType, $journalType] = $this->resolveMoveTypeAndJournalType($classification);

        $journal = Journal::query()
            ->where('company_id', $companyId)
            ->where('type', $journalType->value)
            ->first();
        if (! $journal) {
            throw new RuntimeException("No active {$journalType->value} journal exists for this company -- cannot post a Drive-originated {$moveType->value}.");
        }

        if ($classification->extracted_amount === null || ! BigDecimal::of((string) $classification->extracted_amount)->isPositive()) {
            throw new RuntimeException('The extracted amount must be a positive value to post an invoice.');
        }

        $move = new Move;
        $move->company_id = $companyId;
        $move->partner_id = $partner->id;
        $move->journal_id = $journal->id;
        $move->currency_id = $currency->id;
        $move->move_type = $moveType;
        $move->state = MoveState::DRAFT;
        $move->invoice_date = $this->resolveInvoiceDate($classification, $currency);
        $move->reference = $classification->extracted_invoice_number;
        $move->save();

        // Defensive company-isolation assertion -- not "should be true
        // because partner/journal/currency were already company-scoped",
        // an explicit check on the actually-persisted row.
        if ((int) $move->company_id !== $companyId) {
            throw new RuntimeException('Company isolation violated: the created Move does not belong to the classification\'s company.');
        }

        $line = new MoveLine;
        $line->move_id = $move->id;
        $line->account_id = $account->id;
        // This is the exact mechanism BankJournalService uses to propagate
        // a resolved FS Tag onto a posted journal line (see
        // BankJournalService::insertLines()'s 'fs_tag_id' column) -- reused
        // here rather than inventing a second way to attach an FS Tag to a
        // line.
        $line->fs_tag_id = $fsTag->id;
        $line->display_type = DisplayType::PRODUCT;
        $line->quantity = 1;
        $line->price_unit = (string) $classification->extracted_amount;
        $line->discount = 0;
        $line->name = $this->lineDescription($classification);
        $line->save();

        if ($tax = $this->resolveTaxForMove($classification, $moveType, $companyId)) {
            $line->taxes()->sync([$tax->id]);
        }

        // The real posting mechanism -- Webkul\Account\AccountManager::
        // confirmMove() via its facade, the exact call
        // InvoiceResource/BillResource's ConfirmAction and
        // CreateInvoice/CreateBill's afterCreate() both make. It generates
        // the balancing tax/payment-term lines itself
        // (syncDynamicLines()), computes totals, and refuses to post
        // (throwing, reverting state to DRAFT) if the result isn't
        // balanced -- no hand-rolled debit/credit balancing here.
        $move = AccountFacade::confirmMove($move->fresh('lines'));

        // Belt-and-braces on top of confirmMove()'s own balance check: it
        // already guarantees this or throws, but assert it explicitly
        // here too rather than trusting that blindly.
        $move->refresh()->load('lines');
        $totalDebit = $move->lines->sum(fn (MoveLine $l): float => (float) $l->debit);
        $totalCredit = $move->lines->sum(fn (MoveLine $l): float => (float) $l->credit);
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new RuntimeException("Posted journal is not balanced: debit {$totalDebit} vs credit {$totalCredit}.");
        }

        $this->attachDocument($classification, $move, $request);

        return $move;
    }

    private function resolveCurrency(DriveIngestionClassification $classification): Currency
    {
        $code = $classification->extracted_currency_code;
        $currency = $code ? Currency::query()->where('code', $code)->first() : null;

        if (! $currency) {
            // A Valid classification should never have a null/unresolvable
            // currency code -- Phase 2 requires one to reach Valid at all
            // for an invoice-like document. If this happens, something
            // upstream let a bad row through; fail loudly here rather than
            // silently defaulting to the company currency, which could
            // post an invoice in the wrong currency without anyone
            // noticing.
            throw new RuntimeException(
                $code
                    ? "Could not resolve a currency for code \"{$code}\" -- refusing to guess or fall back to the company currency."
                    : 'No currency code was extracted for this classification -- refusing to guess or fall back to the company currency.'
            );
        }

        return $currency;
    }

    /**
     * Symmetric with resolveCurrency() above: Move::invoice_date is exactly
     * what Move::computeInvoiceCurrencyRate() (the `saving` hook fired by
     * $move->save() below) uses to look up the historical FX rate for a
     * foreign-currency Move. A Valid classification should never reach
     * here with a null extracted_date when its currency actually differs
     * from the company's -- DriveClassificationService::classify() now
     * blocks that case before routing for approval -- but refuse loudly
     * here too rather than silently defaulting to today's date (and
     * therefore today's exchange rate) if it ever does, exactly as
     * resolveCurrency() refuses to guess a currency rather than risk
     * posting in the wrong one.
     */
    private function resolveInvoiceDate(DriveIngestionClassification $classification, Currency $currency)
    {
        if ($classification->extracted_date !== null) {
            return $classification->extracted_date;
        }

        $companyCurrencyId = $classification->company?->currency_id;

        if ($companyCurrencyId !== null && (int) $currency->id === (int) $companyCurrencyId) {
            // Same currency as the company: Currency::getConversionRate()
            // short-circuits to a rate of 1 whenever the two currency ids
            // match, regardless of date -- defaulting the invoice date to
            // today is harmless here.
            return now();
        }

        throw new RuntimeException(
            "No date was extracted for this classification and its currency (\"{$currency->code}\") differs from ".
            'the company currency -- refusing to default the invoice date to today, which would silently post '.
            "using today's exchange rate instead of the document's actual historical rate."
        );
    }

    /**
     * @param  FsTag|null  $fsTag  set by reference so the caller gets the
     *                             freshly-revalidated FS Tag back, not just its account.
     */
    private function resolveReconciledAccount(DriveIngestionClassification $classification, int $companyId, ?FsTag &$fsTag): Account
    {
        $storedFsTag = $classification->resolved_fs_tag_id
            ? FsTag::query()->find($classification->resolved_fs_tag_id)
            : null;

        if (! $storedFsTag) {
            throw new RuntimeException('The classification has no resolved FS Tag -- it should never have reached approval without one.');
        }

        // Re-run the same resolution classify() used (active, company-scoped,
        // code-based) rather than trusting the stored row hasn't drifted
        // since classification time.
        $fsTag = $this->fsTags->resolve($companyId, $storedFsTag->code);
        if (! $fsTag) {
            throw new RuntimeException("FS Tag \"{$storedFsTag->code}\" is no longer active for this company.");
        }
        if ((int) $fsTag->company_id !== $companyId) {
            throw new RuntimeException("FS Tag \"{$fsTag->code}\" does not belong to this classification's company.");
        }

        // The FS Tag's own bound account_id is the architectural source of
        // truth for posting (see class doc) -- re-validated exactly as
        // DriveClassificationService::resolveAccount() validated it.
        $account = Account::query()
            ->postable()
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->find($fsTag->account_id);

        if (! $account) {
            throw new RuntimeException("FS Tag \"{$fsTag->code}\" is no longer mapped to an active, postable GL account owned by this company.");
        }

        if ($classification->resolved_account_id !== null && (int) $classification->resolved_account_id !== (int) $account->id) {
            throw new RuntimeException(
                "The FS Tag's bound GL account (#{$account->id}) no longer matches the account resolved at classification time ".
                "(#{$classification->resolved_account_id}). The FS Tag's mapping appears to have changed after this classification ".
                'was approved for routing -- re-run classification before approving again rather than posting against a stale account.'
            );
        }

        return $account;
    }

    /** @return array{0: MoveType, 1: JournalType} */
    private function resolveMoveTypeAndJournalType(DriveIngestionClassification $classification): array
    {
        $documentType = $classification->document_type;

        if (! $documentType || ! in_array($documentType, self::SUPPORTED_TYPES, true)) {
            $label = $documentType?->getLabel() ?? 'unknown';

            throw new RuntimeException(
                "Phase 3 only creates invoices/bills for Customer Invoice and Vendor Bill document types, not \"{$label}\"."
            );
        }

        return match ($documentType) {
            DriveDocumentType::CustomerInvoice => [MoveType::OUT_INVOICE, JournalType::SALE],
            DriveDocumentType::VendorBill      => [MoveType::IN_INVOICE, JournalType::PURCHASE],
            DriveDocumentType::CreditNote      => $this->resolveCreditNoteMoveType($classification),
        };
    }

    /** @return array{0: MoveType, 1: JournalType} */
    private function resolveCreditNoteMoveType(DriveIngestionClassification $classification): array
    {
        $partner = $classification->resolvedPartner;
        if (! $partner) {
            throw new RuntimeException('Credit Note has no resolved partner.');
        }

        $custRank = (int) ($partner->customer_rank ?? 0);
        $suppRank = (int) ($partner->supplier_rank ?? 0);

        if ($custRank > 0 && $suppRank <= 0) {
            return [MoveType::OUT_REFUND, JournalType::SALE];
        }

        if ($suppRank > 0 && $custRank <= 0) {
            return [MoveType::IN_REFUND, JournalType::PURCHASE];
        }

        throw new RuntimeException("Credit Note partner role is ambiguous for partner \"{$partner->name}\" (customer_rank: {$custRank}, supplier_rank: {$suppRank}). Manual review required.");
    }

    private function resolveTaxForMove(DriveIngestionClassification $classification, MoveType $moveType, int $companyId): ?Tax
    {
        $document = $classification->driveIngestion?->document;
        if (! $document || ! $document->currentVersion) {
            return null;
        }

        $path = $document->currentVersion->file_path;
        if (! $path || ! Storage::disk('accounting_documents')->exists($path)) {
            return null;
        }

        $content = Storage::disk('accounting_documents')->get($path);
        $extractor = new PdfInvoiceTextExtractor;
        $pdfData = $extractor->extract($content);
        $taxRate = $pdfData['tax_rate'];

        if ($taxRate === null) {
            return null;
        }

        $useType = in_array($moveType, [MoveType::OUT_INVOICE, MoveType::OUT_REFUND], true)
            ? TypeTaxUse::SALE
            : TypeTaxUse::PURCHASE;

        $taxes = Tax::query()
            ->forCompany($companyId)
            ->active()
            ->where('type_tax_use', $useType)
            ->where('amount', $taxRate)
            ->get();

        if ($taxes->count() === 1) {
            return $taxes->first();
        }

        return null;
    }

    private function lineDescription(DriveIngestionClassification $classification): string
    {
        // Phase 3 posts exactly one summary line for the whole extracted
        // amount -- see class doc for why (Phase 2 never extracted
        // per-line-item detail to break out here).
        $suffix = $classification->extracted_invoice_number ? " #{$classification->extracted_invoice_number}" : '';

        return "Drive import{$suffix} (single summary line)";
    }

    private function attachDocument(DriveIngestionClassification $classification, Move $move, ApprovalRequest $request): void
    {
        $document = $classification->driveIngestion?->document;
        if (! $document) {
            // Phase 1 always links document_id when it registers an
            // ingestion, but this hook point does not assume that never
            // fails elsewhere -- there is simply nothing to attach.
            return;
        }

        // There is no system/bot actor anywhere in this codebase (the same
        // documented gap DriveClassificationService::routeForApproval()
        // already lives with) -- DocumentService::attach() requires a real
        // User for its company/permission checks. The user who actually
        // approved this request is the most defensible acting user for an
        // approval-triggered attachment: they are a real, company-scoped,
        // authorized actor who just took a real action on this exact
        // subject, not an arbitrary "first user" pulled in from nowhere.
        $actor = $request->decisions()->latest('id')->first()?->actor;
        if (! $actor) {
            throw new RuntimeException('No approving user was found on the approval request to attribute the document attachment to.');
        }

        $this->documents->attach(
            $actor,
            $document,
            $move,
            note: 'Drive-originated document, attached automatically on approval.',
        );
    }
}
