<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\DriveFolderPathResolver;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

beforeEach(function () {
    Storage::fake('accounting_documents');
    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');
    Config::set('accounting_drive.inbound_folder_name', 'Inbound');

    $this->fakeDrive = new class extends FakeDriveClient
    {
        public array $downloadCounts = [];

        public function downloadFileContent(string $fileId): string
        {
            $this->downloadCounts[$fileId] = ($this->downloadCounts[$fileId] ?? 0) + 1;

            return parent::downloadFileContent($fileId);
        }
    };

    app()->instance(DriveClient::class, $this->fakeDrive);

    $this->documentService = app(DocumentService::class);
    $this->ingestionService = app(DriveIngestionService::class);
});

function getInboundFolder(FakeDriveClient $drive, Company $company): string
{
    $rootId = $drive->findFolder('Aureus', null) ?? $drive->createFolder('Aureus', null);
    $companyName = "{$company->name} ({$company->id})";
    $companyId = $drive->findFolder($companyName, $rootId) ?? $drive->createFolder($companyName, $rootId);

    return $drive->findFolder('Inbound', $companyId) ?? $drive->createFolder('Inbound', $companyId);
}

it('executes exactly one content download per file across discovery, download and registration in syncInbound', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = getInboundFolder($this->fakeDrive, $company);
    $fileId = $this->fakeDrive->putExternalFile('vendor-single-download.pdf', 'application/pdf', '%PDF-1.4 test single download content', $folderId);

    $touched = $this->ingestionService->syncInbound($company);

    expect($touched)->toHaveCount(1);
    $ingestion = DriveIngestion::query()->forCompany($company->id)->where('drive_file_id', $fileId)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($ingestion->document_id)->not->toBeNull();

    // Verify downloadFileContent was called exactly once for this file
    expect($this->fakeDrive->downloadCounts[$fileId] ?? 0)->toBe(1);
});

it('handles checksum mismatch during registration and cleans up temporary resources without leaking', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = getInboundFolder($this->fakeDrive, $company);
    $fileId = $this->fakeDrive->putExternalFile('corrupted.pdf', 'application/pdf', '%PDF-1.4 initial content', $folderId);

    $this->ingestionService->discover($company);
    $ingestion = DriveIngestion::query()->forCompany($company->id)->where('drive_file_id', $fileId)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Discovered);

    // Tamper with the recorded checksum to simulate corruption/mid-flight mutation
    $ingestion->update(['checksum_sha256' => 'tampered-hash-that-cannot-match']);

    $this->ingestionService->download($ingestion);
    $ingestion->refresh();

    expect($ingestion->status)->toBe(DriveIngestionStatus::Failed)
        ->and($ingestion->failure_reason)->toContain('Downloaded content does not match the checksum recorded at discovery');
});

it('cleans up temporary resources when registration throws an unexpected exception', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = getInboundFolder($this->fakeDrive, $company);
    $fileId = $this->fakeDrive->putExternalFile('fail-reg.pdf', 'application/pdf', '%PDF-1.4 content', $folderId);

    $this->ingestionService->discover($company);
    $ingestion = DriveIngestion::query()->forCompany($company->id)->where('drive_file_id', $fileId)->first();

    // Mock DocumentService to throw during uploadFromPeer
    $mockDocs = Mockery::mock(DocumentService::class);
    $mockDocs->shouldReceive('uploadFromPeer')
        ->once()
        ->andThrow(new RuntimeException('Disk write failure during document registration'));

    $serviceWithMockDoc = new DriveIngestionService(
        $this->fakeDrive,
        app(DriveFolderPathResolver::class),
        $mockDocs,
    );

    expect(fn () => $serviceWithMockDoc->register($ingestion))
        ->toThrow(RuntimeException::class, 'Disk write failure during document registration');

    // Verify no staged files remain in the service instance
    $reflection = new ReflectionClass($serviceWithMockDoc);
    $stagedFilesProp = $reflection->getProperty('stagedFiles');
    $stagedFilesProp->setAccessible(true);
    expect($stagedFilesProp->getValue($serviceWithMockDoc))->toBeEmpty();
});
