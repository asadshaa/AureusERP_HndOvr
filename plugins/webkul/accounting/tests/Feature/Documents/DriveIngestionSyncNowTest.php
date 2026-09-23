<?php

/**
 * Regression coverage for the Phase 4 review findings fixed in this diff:
 *
 *   1. discover() alone never chains into download()/register(), so
 *      nothing ever progresses past Discovered in production (both the
 *      scheduled job and the manual "Sync Now" action).
 *   2/3. The "Sync Now" summary silently dropped Registered (and other)
 *      statuses from its counted breakdown, and its "needs review" figure
 *      was structurally incapable of reflecting the sync that just ran.
 *   4/6/7/8. Nothing serialized concurrent discover()/download()/
 *      register() calls for the same company (scheduled job vs. manual
 *      click), racing discoverOne()'s check-then-create against the
 *      unique(company_id, drive_file_id) constraint.
 *   5. The manual "Sync Now" action was gated on the read-only
 *      ViewDocuments permission, letting zero-write auditor roles trigger
 *      live Drive API calls and DB writes.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages\ListDriveIngestionClassifications;
use Webkul\Accounting\Jobs\DiscoverDriveIngestionsJob;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

use Filament\Notifications\Livewire\Notifications;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;

beforeEach(function () {
    Storage::fake('accounting_documents');
    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');
    Config::set('accounting_drive.inbound_folder_name', 'Inbound');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);
});

/**
 * Same 3-segment walk DriveIngestionDiscoveryTest's own inboundFolderIdFor()
 * builds -- duplicated (rather than shared) for the same reason
 * DriveIngestionEndToEndTest's e2eInboundFolderId() is: Pest loads every
 * test file's top-level functions into one global namespace.
 */
function syncNowInboundFolderId(DriveClient $drive, Company $company): string
{
    $rootId = $drive->findFolder('Aureus', null) ?? $drive->createFolder('Aureus', null);
    $companyName = "{$company->name} ({$company->id})";
    $companyId = $drive->findFolder($companyName, $rootId) ?? $drive->createFolder($companyName, $rootId);

    return $drive->findFolder('Inbound', $companyId) ?? $drive->createFolder('Inbound', $companyId);
}

/**
 * A partner and FS-Tag/GL-account pair so a well-formed "BILL-..."
 * filename (DriveClassificationService::extractFromFilename()'s
 * recognized pattern) resolves cleanly to DriveClassificationStatus::Valid
 * with a null validation_issues, instead of NeedsReview with issues.
 * Deliberately used by every test here that renders
 * ListDriveIngestionClassifications through Livewire: rendering a row
 * with a non-null validation_issues hits a separate, pre-existing bug in
 * DriveIngestionClassificationResource's "Issues" column (its
 * formatStateUsing(fn (?array $state) ...) is fed one string element at a
 * time by Filament's own array/list state handling, not the whole array)
 * -- unrelated to the Phase 4 findings this file covers, so it is
 * side-stepped here rather than fixed as part of this change.
 */
function syncNowCleanClassificationFixture(Company $company): void
{
    $account = Account::factory()->create([
        'code'         => 'EXP-'.uniqid(),
        'name'         => 'Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $company->currency_id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $account->companies()->attach($company->id);

    FsTag::query()->create([
        'company_id' => $company->id,
        'account_id' => $account->id,
        'code'       => 'FS-EXP',
        'name'       => 'Office Expense',
        'is_active'  => true,
    ]);

    Partner::factory()->create(['company_id' => $company->id, 'name' => 'Acme']);
}

it('chains discover through download, register and classification when the scheduled job runs (Finding 1: job path)', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = syncNowInboundFolderId($this->fakeDrive, $company);
    $this->fakeDrive->putExternalFile('vendor-invoice.pdf', 'application/pdf', '%PDF-1.4 job path', $folderId);

    (new DiscoverDriveIngestionsJob($company->id))->handle(app(DriveIngestionService::class));

    $ingestion = DriveIngestion::query()->forCompany($company->id)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($ingestion->document_id)->not->toBeNull();

    expect(DriveIngestionClassification::query()->where('drive_ingestion_id', $ingestion->id)->exists())->toBeTrue();
});

it('chains discover through download and register when "Sync Drive now" is clicked manually (Finding 1: manual action path)', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);
    $company = Company::find($user->default_company_id);
    syncNowCleanClassificationFixture($company);

    $folderId = syncNowInboundFolderId($this->fakeDrive, $company);
    $this->fakeDrive->putExternalFile('BILL-1001_Acme_500.00PKR_2026-09-01_FS-EXP.pdf', 'application/pdf', '%PDF-1.4 manual path', $folderId);

    test()->actingAs($user);

    Livewire::test(ListDriveIngestionClassifications::class)
        ->assertActionVisible('syncDriveNow')
        ->callAction('syncDriveNow');

    $ingestion = DriveIngestion::query()->forCompany($company->id)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($ingestion->document_id)->not->toBeNull();
});

it('hides the Sync Drive now action from a read-only (auditor-like) user without manage-documents permission (Finding 5)', function () {
    $auditor = documentTestUser(permissions: [AccountingPermissions::ViewDocuments, AccountingPermissions::DownloadDocuments]);

    test()->actingAs($auditor);

    Livewire::test(ListDriveIngestionClassifications::class)
        ->assertActionHidden('syncDriveNow');
});

it('shows and allows the Sync Drive now action for a user with manage-documents permission (Finding 5)', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);

    test()->actingAs($user);

    Livewire::test(ListDriveIngestionClassifications::class)
        ->assertActionVisible('syncDriveNow')
        ->callAction('syncDriveNow')
        ->assertHasNoActionErrors();
});

it('reconciles the Sync Drive now summary counts with the headline total and drops the stale needs-review figure (Findings 2 & 3)', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);
    $company = Company::find($user->default_company_id);
    syncNowCleanClassificationFixture($company);

    $folderId = syncNowInboundFolderId($this->fakeDrive, $company);
    $this->fakeDrive->putExternalFile('BILL-1002_Acme_500.00PKR_2026-09-01_FS-EXP.pdf', 'application/pdf', '%PDF-1.4 counts', $folderId);

    test()->actingAs($user);

    Livewire::test(ListDriveIngestionClassifications::class)
        ->callAction('syncDriveNow');

    // Reads (and clears, via session()->pull()) the notification stashed
    // by Notification::send() -- this must be the only place that reads
    // it, since Filament's own assertNotified() would consume it via an
    // identical throwaway component and leave nothing here to inspect
    // the body of.
    $notificationsComponent = new Notifications;
    $notificationsComponent->mount();
    $sent = $notificationsComponent->notifications->first();

    expect($sent)->not->toBeNull()
        ->and($sent->getTitle())->toBe('1 file(s) found in the Drive inbound folder');

    // Before the fix, a Registered ingestion fell through the match()'s
    // `default => null` arm and was silently dropped from every bucket,
    // so "New documents" stayed 0 even though "1 file(s) found" was the
    // title -- i.e. the breakdown did not reconcile with the headline.
    expect($sent->getBody())
        ->toContain('New documents: 1')
        ->not->toContain('needs review');
});

it('serializes syncInbound() per company behind a real lock, so a concurrent attempt for the same company cannot acquire it mid-run (Findings 4, 6, 7 & 8)', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $spyDrive = new class extends FakeDriveClient
    {
        public $onListFiles = null;

        public function listFiles(string $parentFolderId): array
        {
            if ($this->onListFiles) {
                ($this->onListFiles)();
            }

            return parent::listFiles($parentFolderId);
        }
    };
    app()->instance(DriveClient::class, $spyDrive);

    $folderId = syncNowInboundFolderId($spyDrive, $company);
    $spyDrive->putExternalFile('vendor-invoice.pdf', 'application/pdf', '%PDF-1.4 lock check', $folderId);

    $lockWasHeldDuringDiscovery = null;

    $spyDrive->onListFiles = function () use (&$lockWasHeldDuringDiscovery, $company) {
        // Probe the exact same lock key syncInbound() should already be
        // holding while discover() (which calls listFiles()) runs inside
        // it -- a concurrent scheduled-job run or a concurrent manual
        // "Sync Now" click for the same company would make this exact
        // same probe, and must fail to acquire it.
        $probe = Cache::lock("accounting-drive-ingestion:company:{$company->id}", 1);
        $acquired = $probe->get();
        $lockWasHeldDuringDiscovery = ! $acquired;

        if ($acquired) {
            $probe->release();
        }
    };

    app(DriveIngestionService::class)->syncInbound($company);

    expect($lockWasHeldDuringDiscovery)->toBeTrue();
});
