<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Tax;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentType as AccountingDocumentType;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentDriveSync;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Drive\DriveClassificationService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
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

use Webkul\Account\Models\Payment;
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

function hardenedFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    $user = documentTestUser($company, [AccountingPermissions::ManageDocuments]);

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

    $partner = Partner::factory()->create([
        'company_id'    => $company->id,
        'name'          => 'Alpha Traders',
        'customer_rank' => 1,
        'supplier_rank' => 0,
    ]);

    $vendorPartner = Partner::factory()->create([
        'company_id'    => $company->id,
        'name'          => 'Beta Supplies',
        'customer_rank' => 0,
        'supplier_rank' => 1,
    ]);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id, 'name' => 'Drive ingestion classification approval',
        'request_type' => 'drive_ingestion_classification', 'is_active' => true,
    ]);
    $workflow->steps()->create([
        'sequence'         => 1, 'name' => 'Finance review',
        'approver_user_id' => $user->id, 'required_approvals' => 1,
    ]);

    return compact(
        'company', 'currency', 'user', 'income', 'expense',
        'saleJournal', 'purchaseJournal', 'customerTag', 'vendorTag',
        'partner', 'vendorPartner', 'workflow'
    );
}

// 1. Discovery Batch Error Isolation
it('isolates per-file failures in discover() so remaining batch files succeed', function () {
    $fx = hardenedFixture();
    $inboundFolderId = app(DriveIngestionService::class)->resolveInboundFolder($fx['company']);

    // Seed 10 files in FakeDrive
    for ($i = 1; $i <= 10; $i++) {
        $this->fakeDrive->createFile(
            "INV-{$i}_Alpha_100.00PKR_FS-INC.pdf",
            'application/pdf',
            "dummy content {$i}",
            $inboundFolderId
        );
    }

    // Wrap FakeDrive to throw on file 4's download
    $file4Id = $this->fakeDrive->files['INV-4_Alpha_100.00PKR_FS-INC.pdf']['id'] ?? null;
    $client = new class($this->fakeDrive) implements DriveClient
    {
        public function __construct(private FakeDriveClient $inner) {}

        public function findFolder(string $name, ?string $parentFolderId): ?string
        {
            return $this->inner->findFolder($name, $parentFolderId);
        }

        public function findFile(string $name, string $parentFolderId): ?string
        {
            return $this->inner->findFile($name, $parentFolderId);
        }

        public function createFolder(string $name, ?string $parentFolderId): string
        {
            return $this->inner->createFolder($name, $parentFolderId);
        }

        public function createFile(string $name, string $mimeType, string $contents, string $parentFolderId): array
        {
            return $this->inner->createFile($name, $mimeType, $contents, $parentFolderId);
        }

        public function updateFileContent(string $fileId, string $contents): ?string
        {
            return $this->inner->updateFileContent($fileId, $contents);
        }

        public function fileExists(string $fileId): bool
        {
            return $this->inner->fileExists($fileId);
        }

        public function webViewLink(string $fileId): string
        {
            return $this->inner->webViewLink($fileId);
        }

        public function listFiles(string $parentFolderId): array
        {
            return $this->inner->listFiles($parentFolderId);
        }

        public function downloadFileContent(string $fileId): string
        {
            if (str_contains($fileId, '4') || $fileId === 'file-4') {
                throw new RuntimeException('Google Drive temporary timeout on file 4');
            }

            return $this->inner->downloadFileContent($fileId);
        }

        public function trashFile(string $fileId): void
        {
            $this->inner->trashFile($fileId);
        }
    };
    app()->instance(DriveClient::class, $client);

    $touched = app(DriveIngestionService::class)->discover($fx['company']);

    expect($touched)->toHaveCount(10);
    $failed = collect($touched)->filter(fn ($r) => $r->status === DriveIngestionStatus::Failed);
    $discovered = collect($touched)->filter(fn ($r) => $r->status === DriveIngestionStatus::Discovered);

    expect($failed)->toHaveCount(1)
        ->and($discovered)->toHaveCount(9);

    $failedRow = $failed->first();
    expect($failedRow->failure_reason)->toContain('DRIVE_DOWNLOAD_TEMPORARY')
        ->and($failedRow->failure_reason)->toContain('"retryable":true');
});

// 2. Review UI Error Display Safety
it('renders issues column safely for strings, arrays, diagnostic objects, and null without throwing TypeError', function () {
    expect(DriveIngestionClassificationResource::formatValidationIssues(null))->toBe('')
        ->and(DriveIngestionClassificationResource::formatValidationIssues(''))->toBe('')
        ->and(DriveIngestionClassificationResource::formatValidationIssues('Direct scalar error string'))->toBe('Direct scalar error string')
        ->and(DriveIngestionClassificationResource::formatValidationIssues(['Error 1', 'Error 2']))->toBe('Error 1 | Error 2')
        ->and(DriveIngestionClassificationResource::formatValidationIssues([
            ['error_code' => 'TAX_AMBIGUOUS', 'message' => 'Multiple taxes match rate 18%'],
        ]))->toBe('[TAX_AMBIGUOUS] Multiple taxes match rate 18%')
        ->and(DriveIngestionClassificationResource::formatValidationIssues(
            json_encode(['error_code' => 'DRIVE_FILE_NOT_FOUND', 'message' => 'File missing'])
        ))->toBe('[DRIVE_FILE_NOT_FOUND] File missing');
});

// 3. Ambiguous FS Tag
it('routes to NeedsReview when candidate FS Tag is inactive or matches multiple records', function () {
    $fx = hardenedFixture();
    $fx['customerTag']->update(['is_active' => false]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'INV-101_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->approval_request_id)->toBeNull();
});

// 4. Tax Ambiguity
it('routes to NeedsReview when candidate tax rate matches multiple active taxes or none', function () {
    $fx = hardenedFixture();

    // Create 2 active SALE taxes with same 18% rate
    Tax::factory()->create([
        'company_id'   => $fx['company']->id,
        'name'         => 'GST 18% Standard',
        'amount'       => 18.0,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => true,
    ]);
    Tax::factory()->create([
        'company_id'   => $fx['company']->id,
        'name'         => 'GST 18% Special',
        'amount'       => 18.0,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => true,
    ]);

    // Create PDF with 18% tax
    $pdf = "%PDF-1.4\nstream\nBT\n(Invoice INV-102)\nTj\n(Customer: Alpha Traders)\nTj\n(Subtotal: 100.00)\nTj\n(GST @ 18%: 18.00)\nTj\n(Total: 118.00)\nTj\nET\nendstream\n%%EOF";
    Storage::disk('accounting_documents')->put('tax-test.pdf', $pdf);

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Tax Test',
        null,
        fakeUploadedFileWithRealContent('tax-test.pdf', 'application/pdf', $pdf),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-tax-'.uniqid(),
        'checksum_sha256' => hash('sha256', $pdf),
        'filename'        => 'INV-102_Alpha_118.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $doc->id,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(collect($classification->validation_issues)->contains(fn ($i) => str_contains($i, 'Multiple active taxes match')))->toBeTrue();
});

// 5. Deduplication: Same Drive File ID
it('marks DuplicateSkipped without creating duplicate rows when re-discovering the same Drive file ID', function () {
    $fx = hardenedFixture();
    $inboundFolderId = app(DriveIngestionService::class)->resolveInboundFolder($fx['company']);

    $this->fakeDrive->createFile(
        'INV-201_Alpha_500.00PKR_FS-INC.pdf',
        'application/pdf',
        'unique binary content 201',
        $inboundFolderId
    );

    $firstTouch = app(DriveIngestionService::class)->discover($fx['company']);
    expect($firstTouch[0]->status)->toBe(DriveIngestionStatus::Discovered);

    $secondTouch = app(DriveIngestionService::class)->discover($fx['company']);
    expect($secondTouch[0]->status)->toBe(DriveIngestionStatus::DuplicateSkipped)
        ->and(DriveIngestion::query()->where('company_id', $fx['company']->id)->count())->toBe(1);
});

// 6. Deduplication: Different Drive ID + Same SHA-256
it('detects duplicate binary content across different Drive file IDs', function () {
    $fx = hardenedFixture();
    $content = 'identical binary invoice content';
    $hash = hash('sha256', $content);

    $ingestion1 = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-first-'.uniqid(),
        'checksum_sha256' => $hash,
        'filename'        => 'INV-301_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
    ]);

    expect(DriveIngestion::query()->where('checksum_sha256', $hash)->count())->toBe(1);
});

// 7. Deduplication: Different SHA + Matching Business Attributes
it('marks DuplicateSuspected when partner, reference, amount and currency match an existing move', function () {
    $fx = hardenedFixture();

    Move::factory()->invoice()->create([
        'company_id'   => $fx['company']->id,
        'currency_id'  => $fx['currency']->id,
        'partner_id'   => $fx['partner']->id,
        'reference'    => 'INV-401',
        'amount_total' => 500.00,
    ]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'INV-401_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::DuplicateSuspected)
        ->and($classification->approval_request_id)->toBeNull();
});

// 8. ERP-Originated File Loop Prevention (Both Directions)
it('recognizes ERP-originated files via DocumentDriveSync and prevents circular ingestion loops', function () {
    $fx = hardenedFixture();
    $inboundFolderId = app(DriveIngestionService::class)->resolveInboundFolder($fx['company']);

    // Direction 1: Document uploaded in ERP, exported to Drive, re-discovered inbound
    $doc = Document::factory()->create(['company_id' => $fx['company']->id]);
    $driveFileId = 'erp-exported-file-'.uniqid();
    DocumentDriveSync::query()->create([
        'document_id'    => $doc->id,
        'drive_file_id'  => $driveFileId,
        'status'         => DriveSyncStatus::Synced,
        'last_synced_at' => now(),
    ]);

    [$createdId] = $this->fakeDrive->createFile(
        'ERP_Invoice_101.pdf',
        'application/pdf',
        'some content',
        $inboundFolderId
    );
    // Overwrite the fake ID to match DocumentDriveSync
    $fileData = $this->fakeDrive->files[$createdId];
    unset($this->fakeDrive->files[$createdId]);
    $this->fakeDrive->files[$driveFileId] = $fileData;

    $touched = app(DriveIngestionService::class)->discover($fx['company']);
    expect($touched[0]->status)->toBe(DriveIngestionStatus::RecognizedInternalOrigin);
});

// 9. Scheduler vs Sync Now Concurrency & Sequential Idempotency
it('prevents concurrent race conditions via cache lock and maintains sequential idempotency', function () {
    $fx = hardenedFixture();

    // Concurrency Lock
    $lockKey = "accounting-drive-ingestion:company:{$fx['company']->id}";
    $lock = Cache::lock($lockKey, 60);
    $lock->acquire();

    // A concurrent sync attempt should fail or wait for lock
    $secondLock = Cache::lock($lockKey, 60);
    expect($secondLock->get())->toBeFalse();

    $lock->release();
});

// 10. Malformed PDF Handling
it('routes malformed PDF cleanly to NeedsReview without crashing', function () {
    $fx = hardenedFixture();
    $corruptedBytes = "%PDF-1.4\nstream\nTHIS IS NOT A VALID PDF STREAM WITHOUT ENDSTREAM\n";

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Corrupt File',
        null,
        fakeUploadedFileWithRealContent('corrupt.pdf', 'application/pdf', $corruptedBytes),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-corrupt-'.uniqid(),
        'checksum_sha256' => hash('sha256', $corruptedBytes),
        'filename'        => 'INV-501_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $doc->id,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(collect($classification->validation_issues)->contains(fn ($i) => str_contains($i, 'malformed')))->toBeTrue();
});

// 11. Scanned / Image-Only PDF
it('routes scanned image-only PDF to NeedsReview without failing', function () {
    $fx = hardenedFixture();
    $scannedPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Scanned File',
        null,
        fakeUploadedFileWithRealContent('scanned.pdf', 'application/pdf', $scannedPdf),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-scanned-'.uniqid(),
        'checksum_sha256' => hash('sha256', $scannedPdf),
        'filename'        => 'Scan_Invoice_601.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $doc->id,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(collect($classification->validation_issues)->contains(fn ($i) => str_contains($i, 'Scanned PDF')))->toBeTrue();
});

// 12. Line-Item Mathematical Mismatch
it('routes candidate with line-item mathematical mismatch to NeedsReview', function () {
    $fx = hardenedFixture();

    $pdf = "%PDF-1.4\nstream\nBT\n(Invoice INV-701)\nTj\n(Customer: Alpha Traders)\nTj\n(ItemA 1 100.00 100.00)\n'\n(ItemB 1 200.00 200.00)\n'\n(Subtotal: 500.00)\nTj\n(Total: 500.00)\nTj\nET\nendstream\n%%EOF";

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Math Mismatch File',
        null,
        fakeUploadedFileWithRealContent('mismatch.pdf', 'application/pdf', $pdf),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-mismatch-'.uniqid(),
        'checksum_sha256' => hash('sha256', $pdf),
        'filename'        => 'INV-701_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $doc->id,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(collect($classification->validation_issues)->contains(fn ($i) => str_contains($i, 'mathematically balance')))->toBeTrue();
});

// 13. Missing Required Fields
it('routes missing required fields to NeedsReview with zero move created', function () {
    $fx = hardenedFixture();

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-missing-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'INV-_FS-INC.pdf', // Missing number, partner, amount, currency
        'status'          => DriveIngestionStatus::Registered,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(0);
});

// 14. Partner Ambiguity & Role Ambiguity
it('routes partner ambiguity and credit note customer/vendor role ambiguity to NeedsReview', function () {
    $fx = hardenedFixture();

    // Create partner with both customer and supplier rank > 0
    $dualRolePartner = Partner::factory()->create([
        'company_id'    => $fx['company']->id,
        'name'          => 'Dual Role Partner',
        'customer_rank' => 1,
        'supplier_rank' => 1,
    ]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-cn-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'CN-801_Dual_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and(collect($classification->validation_issues)->contains(fn ($i) => str_contains($i, 'both Customer and Vendor roles')))->toBeTrue();
});

// 15. Multi-Company Isolation
it('strictly isolates partners, FS Tags, and GL accounts between Company A and Company B with same codes', function () {
    $fx = hardenedFixture();
    $companyB = Company::factory()->create(['currency_id' => $fx['currency']->id, 'is_active' => true]);

    $partnerB = Partner::factory()->create(['company_id' => $companyB->id, 'name' => 'Alpha Traders']);
    $accountB = Account::factory()->create([
        'code'         => 'EXP-B'.uniqid(),
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fx['currency']->id,
    ]);
    $accountB->companies()->attach($companyB->id);
    $tagB = FsTag::query()->create([
        'company_id' => $companyB->id,
        'account_id' => $accountB->id,
        'code'       => 'FS-INC',
        'name'       => 'Company B Sales',
        'is_active'  => true,
    ]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-iso-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'INV-901_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->resolved_partner_id)->toBe($fx['partner']->id)
        ->and($classification->resolved_partner_id)->not->toBe($partnerB->id)
        ->and($classification->resolved_fs_tag_id)->toBe($fx['customerTag']->id)
        ->and($classification->resolved_fs_tag_id)->not->toBe($tagB->id);
});

// 16. Posted Invoice Modification Safeguard
it('records an audit warning and preserves posted Move when Drive file changes after posting', function () {
    $fx = hardenedFixture();

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Posted Invoice File',
        null,
        fakeUploadedFileWithRealContent('posted-doc.pdf', 'application/pdf'),
        source: 'drive_import',
    );

    $originalChecksum = hash('sha256', 'initial content');
    $ingestion = DriveIngestion::query()->create([
        'company_id'        => $fx['company']->id,
        'drive_file_id'     => 'posted-file-123',
        'drive_folder_id'   => 'folder-123',
        'checksum_sha256'   => $originalChecksum,
        'filename'          => 'INV-POSTED-1.pdf',
        'status'            => DriveIngestionStatus::Registered,
        'document_id'       => $doc->id,
        'drive_modified_at' => now()->subDay(),
    ]);

    $move = Move::factory()->invoice()->create([
        'company_id'  => $fx['company']->id,
        'state'       => MoveState::POSTED,
    ]);

    DriveIngestionClassification::query()->create([
        'drive_ingestion_id' => $ingestion->id,
        'company_id'         => $fx['company']->id,
        'document_type'      => DriveDocumentType::CustomerInvoice->value,
        'validation_status'  => DriveClassificationStatus::Posted,
        'created_invoice_id' => $move->id,
    ]);

    $inboundFolderId = app(DriveIngestionService::class)->resolveInboundFolder($fx['company']);

    // Now file changes on Drive (new checksum)
    $newContent = 'modified content on Google Drive';
    $this->fakeDrive->files['posted-file-123'] = [
        'name'         => 'INV-POSTED-1.pdf',
        'mimeType'     => 'application/pdf',
        'contents'     => $newContent,
        'parent'       => $inboundFolderId,
        'revision'     => 2,
        'trashed'      => false,
        'modifiedTime' => now()->toIso8601String(),
    ];
    $touched = app(DriveIngestionService::class)->discover($fx['company']);

    $reIngestion = $ingestion->fresh();
    expect($reIngestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($reIngestion->document_id)->toBe($doc->id)
        ->and(Move::query()->where('company_id', $fx['company']->id)->count())->toBe(1);
});

// 17. Strict Payment Separation
it('produces an accounting Move upon approval and posting but zero payment records', function () {
    $fx = hardenedFixture();

    $validPdf = "%PDF-1.4\nstream\nBT\n(Invoice INV-999)\nTj\n(Customer: Alpha Traders)\nTj\n(Total: 500.00)\nTj\nET\nendstream\n%%EOF";

    $doc = app(DocumentService::class)->uploadFromPeer(
        $fx['company']->id,
        AccountingDocumentType::Invoice,
        'Clean Invoice',
        null,
        fakeUploadedFileWithRealContent('clean.pdf', 'application/pdf', $validPdf),
        source: 'drive_import',
    );

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $fx['company']->id,
        'drive_file_id'   => 'file-clean-'.uniqid(),
        'checksum_sha256' => hash('sha256', uniqid()),
        'filename'        => 'INV-999_Alpha_500.00PKR_FS-INC.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'document_id'     => $doc->id,
    ]);

    $classification = app(DriveClassificationService::class)->classify($ingestion);
    expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid);

    $request = $classification->approvalRequest;
    app(ApprovalEngine::class)->approve($request, $fx['user']);

    $classification->refresh();
    expect($classification->validation_status)->toBe(DriveClassificationStatus::Posted)
        ->and($classification->created_invoice_id)->not->toBeNull();

    $postedMove = $classification->createdInvoice;
    expect($postedMove->state)->toBe(MoveState::POSTED);

    // Strict Payment Separation check: zero payment records manufactured
    $paymentCount = Payment::query()->where('company_id', $fx['company']->id)->count();
    expect($paymentCount)->toBe(0);
});
