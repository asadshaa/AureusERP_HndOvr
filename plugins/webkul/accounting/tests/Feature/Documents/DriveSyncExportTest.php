<?php

/**
 * Export direction only (Aureus -> Drive) -- the Drive -> Aureus import
 * direction was explicitly held back for a separate approval and isn't
 * built yet. Of the 14 scenarios in the original spec, this file covers
 * the ones that apply to export:
 *
 *   1. Aureus document -> Drive creation
 *   2. Aureus document -> Drive update
 *   3. Repeated sync is idempotent
 *   9. Company isolation
 *  11. Checksum verification
 *  12. Failed synchronization + retry
 *
 * Permission enforcement (10) is covered in DriveSyncUiTest against the
 * "Sync now"/"Open in Drive" table actions, the same way every other
 * Documents-table action is already tested. Scenarios 4-8, 13, 14 are
 * all import-direction and don't exist yet.
 *
 * Every test binds FakeDriveClient in place of GoogleDriveClient -- no
 * real Google credentials, network calls, or even google/apiclient being
 * loaded are involved, the same relationship Storage::fake() has to real
 * S3.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DriveSyncService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

use Webkul\Accounting\Tests\Helpers\FakeDriveClient;

beforeEach(function () {
    Storage::fake('accounting_documents');
    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);

    $this->documentService = app(DocumentService::class);
    $this->driveSyncService = app(DriveSyncService::class);
});

it('creates a Drive folder path and file the first time a document is exported', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Misc evidence', null,
        fakeUploadedFileWithRealContent('inv-100.pdf', 'application/pdf'),
    );

    $sync = $this->driveSyncService->export($document);

    expect($sync->status)->toBe(DriveSyncStatus::Synced)
        ->and($sync->exists_in_drive)->toBeTrue()
        ->and($sync->drive_file_id)->not->toBeNull()
        ->and($sync->last_synced_checksum)->toBe($document->currentVersion->checksum_sha256);

    // Aureus / {Company} / Accounting / Other Documents -- 4 nested
    // folders, per config/accounting_drive.php's 'default' template.
    expect($this->fakeDrive->folders)->toHaveCount(4);
    $rootFolder = collect($this->fakeDrive->folders)->firstWhere('name', 'Aureus');
    expect($rootFolder)->not->toBeNull();

    $file = $this->fakeDrive->files[$sync->drive_file_id];
    expect($file['name'])->toBe('inv-100.pdf');

    expect($document->audits()->where('action', 'drive_exported')->exists())->toBeTrue();
});

it('updates the SAME Drive file in place when a document gets a new version, never creating a second file', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt-v1.pdf', 'application/pdf'),
    );
    $firstSync = $this->driveSyncService->export($document);
    $originalFileId = $firstSync->drive_file_id;

    $this->documentService->addVersion(
        $user, $document,
        fakeUploadedFileWithRealContent('receipt-v2.pdf', 'application/pdf'),
        changeReason: 'Better scan',
    );
    $document->refresh();

    $secondSync = $this->driveSyncService->export($document);

    expect($secondSync->drive_file_id)->toBe($originalFileId)
        ->and($this->fakeDrive->files)->toHaveCount(1)
        ->and($this->fakeDrive->files[$originalFileId]['revision'])->toBe(2)
        ->and($secondSync->last_synced_checksum)->toBe($document->currentVersion->checksum_sha256)
        ->and($secondSync->last_synced_checksum)->not->toBe($firstSync->last_synced_checksum);

    expect($this->fakeDrive->writeLog)->toHaveCount(2)
        ->and($this->fakeDrive->writeLog[0]['op'])->toBe('create')
        ->and($this->fakeDrive->writeLog[1]['op'])->toBe('update');
});

it('is idempotent -- exporting the same unchanged document twice does not create a duplicate file', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Idempotency check', null,
        fakeUploadedFileWithRealContent('idempotent.pdf', 'application/pdf'),
    );

    $first = $this->driveSyncService->export($document);
    $second = $this->driveSyncService->export($document);
    $third = $this->driveSyncService->export($document);

    expect($first->drive_file_id)->toBe($second->drive_file_id)
        ->and($second->drive_file_id)->toBe($third->drive_file_id)
        ->and($this->fakeDrive->files)->toHaveCount(1);

    // Re-running folder resolution 3 times must not create 3x as many
    // folders as running it once.
    $folderCountAfterThree = count($this->fakeDrive->folders);
    $this->driveSyncService->export($document);
    expect(count($this->fakeDrive->folders))->toBe($folderCountAfterThree);
});

it('gives two companies\' documents separate Drive folder trees, never mixing them', function () {
    $userA = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $userB = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $companyA = Company::find($userA->default_company_id);
    $companyB = Company::find($userB->default_company_id);

    $docA = $this->documentService->upload(
        $userA, $companyA->id, DocumentType::Other, 'Company A doc', null,
        fakeUploadedFileWithRealContent('a.pdf', 'application/pdf'),
    );
    $docB = $this->documentService->upload(
        $userB, $companyB->id, DocumentType::Other, 'Company B doc', null,
        fakeUploadedFileWithRealContent('b.pdf', 'application/pdf'),
    );

    $syncA = $this->driveSyncService->export($docA);
    $syncB = $this->driveSyncService->export($docB);

    expect($syncA->drive_parent_folder_id)->not->toBe($syncB->drive_parent_folder_id);

    $rootFolderId = collect($this->fakeDrive->folders)->search(fn ($folder) => $folder['name'] === 'Aureus');

    $companyFolderNames = collect($this->fakeDrive->folders)
        ->filter(fn ($folder) => $folder['parent'] === $rootFolderId)
        ->pluck('name');

    // The company_id suffix is what actually guarantees isolation (see
    // DriveFolderPathResolver::companySegmentFor()) -- name alone isn't
    // unique, so this asserts the REAL identity, not just the label.
    expect($companyFolderNames)->toContain("{$companyA->name} ({$companyA->id})")
        ->and($companyFolderNames)->toContain("{$companyB->name} ({$companyB->id})")
        ->and($companyFolderNames->unique())->toHaveCount(2);
});

it('keys the company Drive folder on the immutable company_id, not just its editable display name', function () {
    // companies.name does carry a DB-level unique constraint in this
    // schema (confirmed directly against the live table -- there is no
    // migration declaring it inline, so this is easy to miss by reading
    // migrations alone), which already rules out two companies sharing
    // a name today. DriveFolderPathResolver::companySegmentFor() embeds
    // company_id in the folder name anyway, as defense-in-depth against
    // relying on that DB constraint never changing, and so a human
    // browsing Drive can unambiguously match a folder to a company.
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Company id in path check', null,
        fakeUploadedFileWithRealContent('id-in-path.pdf', 'application/pdf'),
    );

    $this->driveSyncService->export($document);

    $companyFolderName = collect($this->fakeDrive->folders)
        ->firstWhere('name', "{$company->name} ({$company->id})");

    expect($companyFolderName)->not->toBeNull();
});

it('fails cleanly and records the error when the stored file has been tampered with', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Tamper check', null,
        fakeUploadedFileWithRealContent('tamper.pdf', 'application/pdf'),
    );

    // Corrupt the stored bytes directly on disk, bypassing DocumentService
    // entirely -- exactly the scenario checksum verification exists for.
    Storage::disk('accounting_documents')->put($document->currentVersion->storage_path, 'tampered bytes');

    expect(fn () => $this->driveSyncService->export($document))
        ->toThrow(RuntimeException::class, 'does not match its recorded checksum');

    expect($document->driveSync()->first()->status)->toBe(DriveSyncStatus::Failed)
        ->and($this->fakeDrive->files)->toBeEmpty();
});

it('records a Failed status with the error on export failure, and a retry can then succeed', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Retry check', null,
        fakeUploadedFileWithRealContent('retry.pdf', 'application/pdf'),
    );

    // Simulate Drive being unreachable on the first attempt by disabling
    // sync mid-flight (readCurrentVersionForSync succeeds; the createFile
    // call is what we make fail, via a broken shared_drive_id causing
    // findFolder/createFolder to blow up would be too coupled -- instead
    // swap the bound client for one whose createFile throws).
    $failingDrive = new class extends FakeDriveClient
    {
        public function createFile(string $name, string $mimeType, string $contents, string $parentFolderId): array
        {
            throw new RuntimeException('Simulated Drive outage');
        }
    };
    app()->instance(DriveClient::class, $failingDrive);
    $failingSyncService = app(DriveSyncService::class);

    expect(fn () => $failingSyncService->export($document))
        ->toThrow(RuntimeException::class, 'Simulated Drive outage');

    $sync = $document->driveSync()->first();
    expect($sync->status)->toBe(DriveSyncStatus::Failed)
        ->and($sync->last_sync_error)->toBe('Simulated Drive outage');

    // Retry: swap back to a healthy client (same pattern the queued job's
    // retry/backoff uses in production -- a later attempt with a working
    // connection) and confirm it recovers cleanly.
    app()->instance(DriveClient::class, $this->fakeDrive);
    $recoveredSyncService = app(DriveSyncService::class);

    $recovered = $recoveredSyncService->export($document);

    expect($recovered->status)->toBe(DriveSyncStatus::Synced)
        ->and($recovered->last_sync_error)->toBeNull();
});

it('recovers a file Drive actually created even though the create response was lost, instead of making a duplicate', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Lost response check', null,
        fakeUploadedFileWithRealContent('lost-response.pdf', 'application/pdf'),
    );

    // FakeDriveClient still records the file in $this->files (Drive's
    // side genuinely succeeded) even though createFile() throws here,
    // simulating a network blip losing the response on its way back.
    $this->fakeDrive->failNextWriteWith(new RuntimeException('Simulated lost response'));

    expect(fn () => $this->driveSyncService->export($document))->toThrow(RuntimeException::class);
    expect($document->driveSync()->first()->status)->toBe(DriveSyncStatus::Failed);

    // Retry: no drive_file_id was ever recorded, but the file already
    // exists in Drive by name -- export() must find and reuse it rather
    // than calling createFile() again.
    $recovered = $this->driveSyncService->export($document);

    expect($recovered->status)->toBe(DriveSyncStatus::Synced)
        ->and($this->fakeDrive->files)->toHaveCount(1)
        ->and(collect($this->fakeDrive->writeLog)->pluck('op')->toArray())->toBe(['create', 'update']);
});

it('does not export when Drive sync is disabled', function () {
    Config::set('accounting_drive.enabled', false);

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Disabled check', null,
        fakeUploadedFileWithRealContent('disabled.pdf', 'application/pdf'),
    );

    expect(fn () => $this->driveSyncService->export($document))
        ->toThrow(RuntimeException::class, 'not enabled');

    expect($this->fakeDrive->files)->toBeEmpty();
});
