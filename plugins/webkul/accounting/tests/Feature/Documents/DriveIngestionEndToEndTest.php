<?php

/**
 * Phase 4: proves Phases 1-3 genuinely compose into one working pipeline,
 * not just that each phase's own isolated tests pass (DriveIngestionDiscoveryTest,
 * DriveClassificationTest and DriveIngestionInvoicePostingTest already cover
 * each phase in isolation -- this file deliberately does not re-test what
 * those already do well; it only proves the hand-offs between phases work
 * with real calls into the real services, a real Drive file, and a real
 * FakeDriveClient the same way DriveIngestionDiscoveryTest uses it).
 *
 * Scenario A: a fresh external file dropped in Drive rides the full chain
 * discover() -> download() -> register() -> classify() (auto-routes for
 * approval) -> real ApprovalEngine decision -> DriveInvoicePostingService
 * posts a balanced Move -> the real BankMatchingPriorityService matches a
 * bank transaction against it.
 *
 * Scenario B: bidirectional loop prevention, full cycle -- a real invoice
 * created and posted inside Aureus, exported to Drive via the existing
 * (export-direction) DriveSyncService, then discovered a second time and
 * confirmed to be recognized as this Aureus instance's own file rather
 * than reprocessed as new external input.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Enums\DocumentType as AccountingDocumentType;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Services\DriveSyncService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

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

    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');
    Config::set('accounting_drive.inbound_folder_name', 'Inbound');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);
});

/**
 * One company wired up with everything every phase needs: a partner and
 * FS-Tag/GL-account pair for VendorBill classification (Scenario A), sale
 * and purchase journals (Scenario B posts a CustomerInvoice, Phase 3's
 * posting service picks the first SALE journal for that move_type), and
 * the same 'drive_ingestion_classification' ApprovalWorkflow
 * DriveClassificationService::routeForApproval() looks up by that exact
 * request_type string.
 */
function e2eFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    // Doubles as: the document-upload/attach actor (needs ManageDocuments,
    // see DocumentService::upload()/attach()), the requester
    // routeForApproval() auto-picks (first active user for the company),
    // and the approver -- exactly the same one-user-wears-three-hats setup
    // DriveIngestionInvoicePostingTest's own postingFixture()/
    // submitAndApprove() already use for the identical reason.
    $user = documentTestUser($company, [AccountingPermissions::ManageDocuments]);

    $accountFor = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency): Account {
        $account = Account::factory()->create([
            'code'         => $code.uniqid(),
            'name'         => $code,
            'account_type' => $type,
            'currency_id'  => $currency->id,
            'is_group'     => false,
            'deprecated'   => false,
            'reconcile'    => $reconcile,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };

    $receivable = $accountFor('AR-', AccountType::ASSET_RECEIVABLE, reconcile: true);
    $payable = $accountFor('AP-', AccountType::LIABILITY_PAYABLE, reconcile: true);
    $expense = $accountFor('EXP-', AccountType::EXPENSE);
    $income = $accountFor('INC-', AccountType::INCOME);

    $saleJournal = Journal::factory()->create([
        'company_id' => $company->id, 'currency_id' => $currency->id,
        'type'       => JournalType::SALE, 'code' => 'SALE'.uniqid(),
    ]);
    $purchaseJournal = Journal::factory()->create([
        'company_id' => $company->id, 'currency_id' => $currency->id,
        'type'       => JournalType::PURCHASE, 'code' => 'PUR'.uniqid(),
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
        'approver_user_id' => $user->id, 'required_approvals' => 1,
    ]);

    return compact(
        'company', 'currency', 'user', 'receivable', 'payable', 'expense', 'income',
        'saleJournal', 'purchaseJournal', 'vendorTag', 'partner', 'workflow',
    );
}

/**
 * Same 3-segment walk DriveIngestionDiscoveryTest's own inboundFolderIdFor()
 * builds -- duplicated here (rather than shared) only because Pest loads
 * every test file's top-level functions into one global namespace, and two
 * files declaring the same function name would fatal at collection time.
 */
function e2eInboundFolderId(FakeDriveClient $drive, Company $company): string
{
    $rootId = $drive->findFolder('Aureus', null) ?? $drive->createFolder('Aureus', null);
    $companyFolderId = $drive->findFolder("{$company->name} ({$company->id})", $rootId)
        ?? $drive->createFolder("{$company->name} ({$company->id})", $rootId);

    return $drive->findFolder('Inbound', $companyFolderId) ?? $drive->createFolder('Inbound', $companyFolderId);
}

it('carries a vendor bill dropped in Drive through discovery, classification, approval, posting and bank matching in one real chain', function () {
    $fx = e2eFixture();

    // Filename recognized by DriveClassificationService::extractFromFilename():
    // BILL-<number> -> VendorBill; segments after it are partner name,
    // amount+currency (no separator, e.g. "500.00PKR"), an ISO date, and an
    // "FS-..." FS Tag code, in any order.
    $folderId = e2eInboundFolderId($this->fakeDrive, $fx['company']);
    $fileId = $this->fakeDrive->putExternalFile(
        'BILL-9001_Acme_500.00PKR_2026-09-01_FS-EXP.pdf',
        'application/pdf',
        '%PDF-1.4 real vendor bill bytes',
        $folderId,
    );

    // --- Phase 1: discover -> download -> register ---
    $ingestionService = app(DriveIngestionService::class);

    $touched = $ingestionService->discover($fx['company']);
    expect($touched)->toHaveCount(1);

    $ingestion = DriveIngestion::query()->where('drive_file_id', $fileId)->first();
    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Discovered);

    $ingestionService->download($ingestion);
    $ingestion->refresh();
    expect($ingestion->status)->toBe(DriveIngestionStatus::Downloaded);

    // register() dispatches ClassifyDriveIngestionJob (QUEUE_CONNECTION=sync
    // in phpunit.xml, so this runs classify() synchronously, exactly as
    // DriveIngestionInvoicePostingTest's own beforeEach() comment notes for
    // the equivalent export-direction dispatch) -- so Phase 2 has already
    // run by the time register() returns.
    $ingestionService->register($ingestion);
    $ingestion->refresh();
    expect($ingestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($ingestion->document_id)->not->toBeNull();

    // --- Phase 2: classification reached Valid and auto-routed for approval ---
    $classification = DriveIngestionClassification::query()
        ->where('drive_ingestion_id', $ingestion->id)
        ->first();

    expect($classification)->not->toBeNull()
        ->and($classification->validation_status)->toBe(DriveClassificationStatus::Valid)
        ->and($classification->validation_issues)->toBeNull()
        ->and($classification->resolved_partner_id)->toBe($fx['partner']->id)
        ->and($classification->resolved_fs_tag_id)->toBe($fx['vendorTag']->id)
        ->and($classification->approval_request_id)->not->toBeNull();

    $request = $classification->approvalRequest;
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending');

    // --- approve through the real ApprovalEngine ---
    app(ApprovalEngine::class)->approve($request, $fx['user']);

    // --- Phase 3: DriveInvoicePostingService posted a balanced Move ---
    $classification->refresh();
    expect($classification->validation_status)->toBe(DriveClassificationStatus::Posted)
        ->and($classification->created_invoice_id)->not->toBeNull()
        ->and($classification->posting_failure_reason)->toBeNull();

    $move = $classification->createdInvoice;
    expect($move)->not->toBeNull()
        ->and($move->state)->toBe(MoveState::POSTED)
        ->and($move->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($move->journal_id)->toBe($fx['purchaseJournal']->id)
        ->and($move->company_id)->toBe($fx['company']->id)
        ->and((float) $move->amount_total)->toBe(500.0);

    $totalDebit = $move->lines->sum(fn (MoveLine $l) => (float) $l->debit);
    $totalCredit = $move->lines->sum(fn (MoveLine $l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit)->and($totalDebit)->toBeGreaterThan(0.0);

    $expenseLine = $move->lines->firstWhere('account_id', $fx['expense']->id);
    expect($expenseLine)->not->toBeNull()
        ->and($expenseLine->fs_tag_id)->toBe($fx['vendorTag']->id);

    // The Drive-imported Document itself ends up attached as evidence on
    // the posted Move -- DriveInvoicePostingService::attachDocument().
    expect($move->documentAttachments()->count())->toBe(1);

    // --- a real bank transaction matched against the Drive-originated Move,
    // through the real BankMatchingPriorityService (no faking here either) ---
    $bankAccount = Account::factory()->create([
        'code'        => 'BANK-'.uniqid(), 'name' => 'Bank', 'account_type' => AccountType::ASSET_CASH,
        'currency_id' => $fx['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $bankAccount->companies()->attach($fx['company']->id);
    $bankJournal = Journal::factory()->create([
        'company_id'          => $fx['company']->id, 'currency_id' => $fx['currency']->id,
        'type'                => JournalType::BANK, 'code' => 'BNK'.uniqid(),
        'suspense_account_id' => $bankAccount->id,
    ]);

    $amount = (float) $move->amount_total;

    $statement = BankStatement::query()->create([
        'company_id'              => $fx['company']->id, 'journal_id' => $bankJournal->id,
        'currency_id'             => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'bank_gl_account_id'      => $bankAccount->id, 'name' => 'E2E match statement', 'reference' => 'STMT-E2E-1',
        'date'                    => now()->toDateString(), 'statement_start_date' => now()->toDateString(), 'statement_end_date' => now()->toDateString(),
        'opening_balance'         => 0, 'total_debits' => $amount, 'total_credits' => 0, 'closing_balance' => -$amount,
        'balance_start'           => 0, 'balance_end' => -$amount, 'balance_end_real' => -$amount,
        'company_opening_balance' => 0, 'company_total_debits' => $amount, 'company_total_credits' => 0,
        'company_closing_balance' => -$amount, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Acceptance Bank', 'bank_account_number' => 'E2E-BANK-ACCT', 'account_title' => 'Operating',
        'original_filename'       => 'e2e-match.csv', 'file_hash' => hash('sha256', 'e2e-match-'.uniqid()),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated,
    ]);
    $line = BankStatementLine::query()->create([
        'journal_id'              => $bankJournal->id, 'company_id' => $fx['company']->id,
        'statement_id'            => $statement->id, 'currency_id' => $fx['currency']->id,
        'original_currency_id'    => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'transaction_date'        => now()->toDateString(), 'value_date' => now()->toDateString(),
        'description'             => 'Payment for BILL-9001', 'reference' => '9001',
        'debit'                   => $amount, 'credit' => 0, 'original_debit' => $amount, 'original_credit' => 0,
        'original_signed_amount'  => -$amount, 'company_debit' => $amount, 'company_credit' => 0,
        'company_signed_amount'   => -$amount, 'amount' => $amount, 'amount_currency' => $amount,
        'amount_residual'         => $amount, 'exchange_rate' => 1, 'rate_date' => now()->toDateString(),
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_type'        => 'debit',
        'transaction_fingerprint' => hash('sha256', 'e2e-match-line-'.uniqid()),
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
        ->and($mapping->offset_account_id)->toBe($fx['payable']->id);
});

it('recognizes a self-exported invoice on re-discovery and never reclassifies, re-approves or re-posts it (bidirectional loop prevention)', function () {
    $fx = e2eFixture();

    // --- create and post a real CustomerInvoice inside Aureus, the same
    // Move+MoveLine+confirmMove() path DriveInvoicePostingService itself
    // uses (see that class's doc for why this is "the real invoice
    // creation path" rather than a parallel one) ---
    $move = new Move;
    $move->company_id = $fx['company']->id;
    $move->partner_id = $fx['partner']->id;
    $move->journal_id = $fx['saleJournal']->id;
    $move->currency_id = $fx['currency']->id;
    $move->move_type = MoveType::OUT_INVOICE;
    $move->state = MoveState::DRAFT;
    $move->invoice_date = now();
    $move->reference = 'INV-LOOP-1';
    $move->save();

    $line = new MoveLine;
    $line->move_id = $move->id;
    $line->account_id = $fx['income']->id;
    $line->fs_tag_id = null;
    $line->display_type = DisplayType::PRODUCT;
    $line->quantity = 1;
    $line->price_unit = '300.0000';
    $line->discount = 0;
    $line->name = 'Aureus-originated invoice';
    $line->save();

    $move = AccountFacade::confirmMove($move->fresh('lines'));
    expect($move->state)->toBe(MoveState::POSTED);

    // --- attach a real Document and export it via the existing
    // (export-direction) DriveSyncService -- exactly the feature this
    // ingestion pipeline must never loop back on ---
    $document = app(DocumentService::class)->upload(
        $fx['user'], $fx['company']->id, AccountingDocumentType::Invoice,
        'Aureus invoice INV-LOOP-1', null,
        fakeUploadedFileWithRealContent('aureus-invoice.pdf', 'application/pdf'),
    );
    app(DocumentService::class)->attach($fx['user'], $document, $move);

    $sync = app(DriveSyncService::class)->export($document);
    expect($sync->drive_file_id)->not->toBeNull();

    // The export lands under Aureus/{company}/Accounting/Invoices/..., not
    // the Inbound folder discover() actually lists -- move the exact same
    // file into Inbound to simulate it being visible there too, exactly as
    // DriveIngestionDiscoveryTest's own RecognizedInternalOrigin test does
    // (a shared/overlapping folder root is the realistic case this guards
    // against; a file discover() never even sees can't prove the loop
    // guard is doing anything).
    $inboundFolderId = e2eInboundFolderId($this->fakeDrive, $fx['company']);
    $this->fakeDrive->files[$sync->drive_file_id]['parent'] = $inboundFolderId;

    $classificationCountBefore = DriveIngestionClassification::query()->forCompany($fx['company']->id)->count();
    $moveCountBefore = Move::query()->where('company_id', $fx['company']->id)->count();

    // --- second discover() call: must recognize, not reprocess ---
    $touched = app(DriveIngestionService::class)->discover($fx['company']);

    $ingestion = DriveIngestion::query()->where('drive_file_id', $sync->drive_file_id)->first();
    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::RecognizedInternalOrigin)
        ->and($ingestion->document_id)->toBeNull();

    expect(collect($touched)->pluck('id'))->toContain($ingestion->id);

    // Never reachable for a RecognizedInternalOrigin row -- confirms
    // nothing downstream (classify/post) could have run for it even if
    // something tried.
    expect(fn () => app(DriveIngestionService::class)->register($ingestion))->toThrow(RuntimeException::class);

    expect(DriveIngestionClassification::query()->forCompany($fx['company']->id)->count())->toBe($classificationCountBefore)
        ->and(Move::query()->where('company_id', $fx['company']->id)->count())->toBe($moveCountBefore);

    // Explicitly, not just "count unchanged": classify() was never even
    // attempted for this file, so there is no NeedsReview/Valid/etc. row
    // for it at all.
    expect(DriveIngestionClassification::query()->where('drive_ingestion_id', $ingestion->id)->exists())->toBeFalse();
});
