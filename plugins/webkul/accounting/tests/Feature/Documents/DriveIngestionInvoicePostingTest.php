<?php

/**
 * Phase 3 of Drive -> Aureus ingestion: on an approval decision for a
 * DriveIngestionClassification, the real invoice/bill gets created and
 * posted through the existing accounts posting architecture (see
 * DriveInvoicePostingService's class doc for exactly what it reuses and
 * why). Deliberately builds classifications directly rather than through
 * DriveClassificationService::classify() -- Phase 2's filename heuristics
 * are already covered by DriveClassificationTest; this file only exercises
 * what happens once a classification reaches Valid and its approval
 * request is decided.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Enums\DocumentType as AccountingDocumentType;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Services\Drive\DriveInvoicePostingService;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    Storage::fake('accounting_documents');

    // Document creation (uploadFromPeer(), used below to seed each Drive
    // ingestion's linked Document) fires DocumentContentChanged, which --
    // when accounting_drive sync is enabled, as this environment's own
    // .env has it -- synchronously dispatches SyncDocumentToDriveJob under
    // the testing QUEUE_CONNECTION=sync. Swap in the same FakeDriveClient
    // DriveIngestionDiscoveryTest uses so that job never makes a real
    // network call; this test is about invoice posting, not Drive sync.
    Config::set('accounting_drive.enabled', true);
    app()->instance(DriveClient::class, new FakeDriveClient);
});

function postingFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    // The approver doubles as the acting user for the document attachment
    // (see DriveInvoicePostingService::attachDocument()) -- needs
    // ManageDocuments for that call to succeed.
    $approver = documentTestUser($company, [AccountingPermissions::ManageDocuments]);

    $account = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency): Account {
        $acc = Account::factory()->create([
            'code'         => $code.uniqid(),
            'name'         => $code,
            'account_type' => $type,
            'currency_id'  => $currency->id,
            'is_group'     => false,
            'deprecated'   => false,
            'reconcile'    => $reconcile,
        ]);
        $acc->companies()->attach($company->id);

        return $acc;
    };

    $receivable = $account('AR-', AccountType::ASSET_RECEIVABLE, reconcile: true);
    $payable = $account('AP-', AccountType::LIABILITY_PAYABLE, reconcile: true);
    $income = $account('INC-', AccountType::INCOME);
    $expense = $account('EXP-', AccountType::EXPENSE);

    $saleJournal = Journal::factory()->create([
        'company_id'  => $company->id, 'currency_id' => $currency->id,
        'type'        => JournalType::SALE, 'code' => 'SALE'.uniqid(),
    ]);
    $purchaseJournal = Journal::factory()->create([
        'company_id'  => $company->id, 'currency_id' => $currency->id,
        'type'        => JournalType::PURCHASE, 'code' => 'PUR'.uniqid(),
    ]);

    $customerTag = FsTag::query()->create([
        'company_id' => $company->id, 'account_id' => $income->id,
        'code'       => 'FS-INC', 'name' => 'Sales Income', 'is_active' => true,
    ]);
    $vendorTag = FsTag::query()->create([
        'company_id' => $company->id, 'account_id' => $expense->id,
        'code'       => 'FS-EXP', 'name' => 'Office Expense', 'is_active' => true,
    ]);

    $partner = Partner::factory()->create(['company_id' => $company->id, 'name' => 'Acme']);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id, 'name' => 'Drive ingestion classification approval',
        'request_type' => 'drive_ingestion_classification', 'is_active' => true,
    ]);
    $workflow->steps()->create([
        'sequence'         => 1, 'name' => 'Finance review',
        'approver_user_id' => $approver->id, 'required_approvals' => 1,
    ]);

    return compact(
        'company', 'currency', 'approver', 'receivable', 'payable', 'income', 'expense',
        'saleJournal', 'purchaseJournal', 'customerTag', 'vendorTag', 'partner', 'workflow',
    );
}

function postingClassification(array $fx, array $overrides = []): DriveIngestionClassification
{
    $document = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Drive import',
        null,
        fakeUploadedFileWithRealContent('drive-import.pdf', 'application/pdf'),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'mime_type'       => 'application/pdf',
        'file_size'       => 100,
        'filename'        => 'INV-'.uniqid().'.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $document->id,
        'discovered_at'   => now(),
        'processed_at'    => now(),
    ]);

    $defaults = [
        'drive_ingestion_id'       => $ingestion->id,
        'company_id'               => $fx['company']->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-'.uniqid(),
        'extracted_partner_name'   => $fx['partner']->name,
        'extracted_amount'         => '750.5000',
        'extracted_currency_code'  => $fx['currency']->code,
        'extracted_date'           => now()->toDateString(),
        'extracted_fs_tag_code'    => $fx['customerTag']->code,
        'resolved_partner_id'      => $fx['partner']->id,
        'resolved_fs_tag_id'       => $fx['customerTag']->id,
        'resolved_account_id'      => $fx['income']->id,
        'validation_status'        => DriveClassificationStatus::Valid,
    ];

    return DriveIngestionClassification::query()->create(array_merge($defaults, $overrides));
}

function submitAndApprove(array $fx, DriveIngestionClassification $classification, bool $approve = true): DriveIngestionClassification
{
    $engine = app(ApprovalEngine::class);

    $request = $engine->submit(
        $classification, $fx['approver'], 'drive_ingestion_classification',
        (string) $classification->extracted_amount,
        ['company_id' => $fx['company']->id],
    );
    $classification->update(['approval_request_id' => $request->id]);

    if ($approve) {
        $engine->approve($request, $fx['approver']);
    } else {
        $engine->reject($request, $fx['approver'], 'Not a real invoice.');
    }

    return $classification->fresh();
}

it('creates a posted Move for an approved CustomerInvoice classification, with a balanced FS-Tagged line and the document attached', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::Posted)
        ->and($result->created_invoice_id)->not->toBeNull()
        ->and($result->posted_at)->not->toBeNull()
        ->and($result->posting_failure_reason)->toBeNull();

    $move = $result->createdInvoice;
    expect($move)->not->toBeNull()
        ->and($move->state)->toBe(MoveState::POSTED)
        ->and($move->move_type)->toBe(MoveType::OUT_INVOICE)
        ->and($move->company_id)->toBe($fx['company']->id)
        ->and($move->journal_id)->toBe($fx['saleJournal']->id)
        ->and((float) $move->amount_total)->toBe(750.5);

    $totalDebit = $move->lines->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $move->lines->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit);

    $productLine = $move->lines->firstWhere('account_id', $fx['income']->id);
    expect($productLine)->not->toBeNull()
        ->and($productLine->fs_tag_id)->toBe($fx['customerTag']->id);

    expect($move->documentAttachments()->count())->toBe(1);
});

it('creates a posted Move for an approved VendorBill classification, using the purchase journal and in_invoice move type', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx, [
        'document_type'         => DriveDocumentType::VendorBill,
        'extracted_fs_tag_code' => $fx['vendorTag']->code,
        'resolved_fs_tag_id'    => $fx['vendorTag']->id,
        'resolved_account_id'   => $fx['expense']->id,
    ]);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::Posted);

    $move = $result->createdInvoice;
    expect($move->state)->toBe(MoveState::POSTED)
        ->and($move->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($move->journal_id)->toBe($fx['purchaseJournal']->id);

    $productLine = $move->lines->firstWhere('account_id', $fx['expense']->id);
    expect($productLine)->not->toBeNull()
        ->and($productLine->fs_tag_id)->toBe($fx['vendorTag']->id);
});

it('creates nothing on a Rejected decision and sets validation_status to Rejected', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx);

    $result = submitAndApprove($fx, $classification, approve: false);

    expect($result->validation_status)->toBe(DriveClassificationStatus::Rejected)
        ->and($result->created_invoice_id)->toBeNull()
        ->and($result->posted_at)->toBeNull();
});

it('never creates a second Move when the Approved decision is re-fired on an already-processed classification', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx);

    $result = submitAndApprove($fx, $classification);
    $firstInvoiceId = $result->created_invoice_id;
    expect($firstInvoiceId)->not->toBeNull();

    // Re-fire the exact same decision-processing hook directly -- this is
    // what a replayed event or a duplicate queue delivery would do.
    $request = $result->approvalRequest;
    app(DriveInvoicePostingService::class)->handleDecision($result->fresh(), $request);

    expect($result->fresh()->created_invoice_id)->toBe($firstInvoiceId)
        ->and(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(1);
});

it('never lets a Company A classification produce a Move for Company B, or vice versa', function () {
    $fxA = postingFixture();
    $fxB = postingFixture();

    $classificationA = postingClassification($fxA);
    $classificationB = postingClassification($fxB);

    $resultA = submitAndApprove($fxA, $classificationA);
    $resultB = submitAndApprove($fxB, $classificationB);

    expect($resultA->createdInvoice->company_id)->toBe($fxA['company']->id)
        ->and($resultB->createdInvoice->company_id)->toBe($fxB['company']->id)
        ->and($resultA->createdInvoice->company_id)->not->toBe($fxB['company']->id);
});

it('records a posting failure and leaves no dangling Move when the currency cannot be resolved', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx, ['extracted_currency_code' => 'ZZZ']);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::PostingFailed)
        ->and($result->created_invoice_id)->toBeNull()
        ->and($result->posted_at)->toBeNull()
        ->and($result->posting_failure_reason)->not->toBeNull()
        ->and($result->posting_failure_reason)->toContain('ZZZ');

    expect(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(0);
});

it('records a posting failure instead of defaulting invoice_date to today when the date is missing and the currency differs from the company currency', function () {
    $fx = postingFixture();

    // extracted_currency_code (USD) differs from the company currency
    // (PKR, see postingFixture()) and extracted_date is null -- exactly
    // the case where defaulting invoice_date to now() would silently bake
    // today's exchange rate into the posted Move instead of the
    // document's real historical rate.
    $classification = postingClassification($fx, [
        'extracted_currency_code' => 'USD',
        'extracted_date'          => null,
    ]);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::PostingFailed)
        ->and($result->created_invoice_id)->toBeNull()
        ->and($result->posted_at)->toBeNull()
        ->and($result->posting_failure_reason)->not->toBeNull()
        ->and($result->posting_failure_reason)->toContain('No date was extracted');

    expect(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(0);
});

it('still posts using today\'s date when the date is missing but the currency matches the company currency', function () {
    $fx = postingFixture();

    // Same-currency case: no FX-rate risk (Currency::getConversionRate()
    // returns 1 for a matching currency id regardless of date), so a
    // missing extracted_date must still fall back to today, unchanged
    // from the pre-fix behaviour, rather than start failing every
    // same-currency posting.
    $classification = postingClassification($fx, ['extracted_date' => null]);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::Posted)
        ->and($result->posting_failure_reason)->toBeNull();

    $move = $result->createdInvoice;
    expect($move)->not->toBeNull()
        ->and($move->invoice_date?->toDateString())->toBe(now()->toDateString());
});

it('records a posting failure when the FS Tag\'s bound account no longer matches the account resolved at classification time', function () {
    $fx = postingFixture();
    // Simulate drift: the classification recorded resolved_account_id from
    // the expense account, but resolved_fs_tag_id still points at the
    // customer (income) tag, whose *current* bound account disagrees.
    $classification = postingClassification($fx, ['resolved_account_id' => $fx['expense']->id]);

    $result = submitAndApprove($fx, $classification);

    expect($result->validation_status)->toBe(DriveClassificationStatus::PostingFailed)
        ->and($result->created_invoice_id)->toBeNull()
        ->and($result->posting_failure_reason)->not->toBeNull();

    expect(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(0);
});

it('lets the existing BankMatchingPriorityService match a real bank transaction against a Drive-originated invoice', function () {
    $fx = postingFixture();
    $classification = postingClassification($fx, ['extracted_invoice_number' => 'DRIVE-BANK-1']);

    $result = submitAndApprove($fx, $classification);
    $move = $result->createdInvoice;

    expect($move->reference)->toBe('DRIVE-BANK-1');

    $bankAccount = Account::factory()->create([
        'code'        => 'BANK-'.uniqid(), 'name' => 'Bank', 'account_type' => AccountType::ASSET_CASH,
        'currency_id' => $fx['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $bankAccount->companies()->attach($fx['company']->id);
    $bankJournal = Journal::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['currency']->id,
        'type'       => JournalType::BANK, 'code' => 'BNK'.uniqid(),
        // Explicit, to avoid Journal::computeSuspenseAccountId() falling
        // back to a global DefaultAccountSettings row -- irrelevant to
        // what this test verifies, and this environment's copy of that
        // setting points at a stale account id.
        'suspense_account_id' => $bankAccount->id,
    ]);

    $amount = (float) $move->amount_total;

    $statement = BankStatement::query()->create([
        'company_id'              => $fx['company']->id, 'journal_id' => $bankJournal->id,
        'currency_id'             => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'bank_gl_account_id'      => $bankAccount->id, 'name' => 'Drive match statement', 'reference' => 'STMT-DRIVE-1',
        'date'                    => now()->toDateString(), 'statement_start_date' => now()->toDateString(), 'statement_end_date' => now()->toDateString(),
        'opening_balance'         => 0, 'total_debits' => 0, 'total_credits' => $amount, 'closing_balance' => $amount,
        'balance_start'           => 0, 'balance_end' => $amount, 'balance_end_real' => $amount,
        'company_opening_balance' => 0, 'company_total_debits' => 0, 'company_total_credits' => $amount,
        'company_closing_balance' => $amount, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Acceptance Bank', 'bank_account_number' => 'DRIVE-BANK-ACCT', 'account_title' => 'Operating',
        'original_filename'       => 'drive-match.csv', 'file_hash' => hash('sha256', 'drive-match-'.uniqid()),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated,
    ]);
    $line = BankStatementLine::query()->create([
        'journal_id'              => $bankJournal->id, 'company_id' => $fx['company']->id,
        'statement_id'            => $statement->id, 'currency_id' => $fx['currency']->id,
        'original_currency_id'    => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'transaction_date'        => now()->toDateString(), 'value_date' => now()->toDateString(),
        'description'             => 'Payment for DRIVE-BANK-1', 'reference' => 'DRIVE-BANK-1',
        'debit'                   => 0, 'credit' => $amount, 'original_debit' => 0, 'original_credit' => $amount,
        'original_signed_amount'  => $amount, 'company_debit' => 0, 'company_credit' => $amount,
        'company_signed_amount'   => $amount, 'amount' => $amount, 'amount_currency' => $amount,
        'amount_residual'         => $amount, 'exchange_rate' => 1, 'rate_date' => now()->toDateString(),
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_type'        => 'credit',
        'transaction_fingerprint' => hash('sha256', 'drive-match-line-'.uniqid()),
        'import_status'           => BankImportStatus::Validated,
    ]);
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fx['company']->id, 'statement_line_id' => $line->id,
        'bank_gl_account_id'  => $bankAccount->id, 'original_currency_id' => $fx['currency']->id,
        'company_currency_id' => $fx['currency']->id, 'exchange_rate' => 1, 'rate_date' => now()->toDateString(),
        'rate_source'         => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped, 'posting_status' => BankPostingStatus::NotPosted,
    ]);

    $runResult = app(BankMatchingPriorityService::class)->run($fx['company']->id);

    expect($runResult['obligations'])->toBe(1);

    $mapping->refresh();
    expect($mapping->matched_move_id)->toBe($move->id)
        ->and($mapping->review_status)->toBe(BankReviewStatus::Suggested)
        ->and($mapping->offset_account_id)->toBe($fx['receivable']->id);
});
