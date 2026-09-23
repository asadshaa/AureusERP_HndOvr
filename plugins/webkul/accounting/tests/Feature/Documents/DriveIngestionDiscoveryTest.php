<?php

/**
 * Phase 1 of Drive -> Aureus ingestion: discovery + dedup + review record
 * only. Deliberately does NOT cover invoice creation, FS Tag resolution,
 * GL posting or approval routing -- those are later phases. Mirrors
 * DriveSyncExportTest's structure and its FakeDriveClient-based approach:
 * no real Google credentials, network calls, or google/apiclient loaded.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
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
    Config::set('accounting_drive.inbound_folder_name', 'Inbound');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);

    $this->documentService = app(DocumentService::class);
    $this->ingestionService = app(DriveIngestionService::class);
});

function inboundFolderIdFor(FakeDriveClient $drive, Company $company): string
{
    // Walks the exact same 3-segment path resolveInboundFolder() builds,
    // so tests can drop a file directly into it without re-implementing
    // DriveIngestionService's own folder resolution.
    $rootId = $drive->findFolder('Aureus', null) ?? $drive->createFolder('Aureus', null);
    $companyName = "{$company->name} ({$company->id})";
    $companyId = $drive->findFolder($companyName, $rootId) ?? $drive->createFolder($companyName, $rootId);

    return $drive->findFolder('Inbound', $companyId) ?? $drive->createFolder('Inbound', $companyId);
}

it('discovers a new file dropped in the inbound folder and records its identity fields', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = inboundFolderIdFor($this->fakeDrive, $company);
    $fileId = $this->fakeDrive->putExternalFile('vendor-invoice.pdf', 'application/pdf', '%PDF-1.4 hello world', $folderId, '2026-09-17T10:00:00Z');

    $touched = $this->ingestionService->discover($company);

    expect($touched)->toHaveCount(1);

    $ingestion = DriveIngestion::query()->where('drive_file_id', $fileId)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->company_id)->toBe($company->id)
        ->and($ingestion->status)->toBe(DriveIngestionStatus::Discovered)
        ->and($ingestion->filename)->toBe('vendor-invoice.pdf')
        ->and($ingestion->mime_type)->toBe('application/pdf')
        ->and($ingestion->file_size)->toBe(strlen('%PDF-1.4 hello world'))
        ->and($ingestion->checksum_sha256)->toBe(hash('sha256', '%PDF-1.4 hello world'))
        ->and($ingestion->document_id)->toBeNull();
});

it('does not create a duplicate row when the same unchanged file is discovered twice', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = inboundFolderIdFor($this->fakeDrive, $company);
    $this->fakeDrive->putExternalFile('receipt.pdf', 'application/pdf', 'same bytes every time', $folderId);

    $this->ingestionService->discover($company);
    $this->ingestionService->discover($company);
    $this->ingestionService->discover($company);

    expect(DriveIngestion::query()->forCompany($company->id)->count())->toBe(1);
});

it('marks a file as RecognizedInternalOrigin instead of registering it, when Aureus itself exported it', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    // Aureus exports a document, which creates a DocumentDriveSync row
    // referencing the resulting drive_file_id -- this is the loop
    // export() would otherwise create if that same file were later
    // "discovered" as though it were new external input.
    $document = $this->documentService->upload(
        $user, $company->id, DocumentType::Other, 'Aureus-originated doc', null,
        fakeUploadedFileWithRealContent('aureus-export.pdf', 'application/pdf'),
    );
    $driveSyncService = app(DriveSyncService::class);
    $sync = $driveSyncService->export($document);

    // Move that exact file into the inbound folder to simulate discover()
    // seeing it there (e.g. a shared "Aureus" folder root also happens to
    // be, or overlap with, the inbound folder in some configurations).
    $inboundFolderId = inboundFolderIdFor($this->fakeDrive, $company);
    $this->fakeDrive->files[$sync->drive_file_id]['parent'] = $inboundFolderId;

    $touched = $this->ingestionService->discover($company);

    $ingestion = DriveIngestion::query()->where('drive_file_id', $sync->drive_file_id)->first();

    expect($ingestion)->not->toBeNull()
        ->and($ingestion->status)->toBe(DriveIngestionStatus::RecognizedInternalOrigin)
        ->and($ingestion->document_id)->toBeNull();

    // register() must never be reachable for this row in practice, but
    // assert directly that it refuses rather than silently registering.
    expect(fn () => $this->ingestionService->register($ingestion))->toThrow(RuntimeException::class);
});

it('keeps two companies\' inbound folders and ingestion rows completely separate', function () {
    $userA = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $userB = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $companyA = Company::find($userA->default_company_id);
    $companyB = Company::find($userB->default_company_id);

    $folderA = inboundFolderIdFor($this->fakeDrive, $companyA);
    $folderB = inboundFolderIdFor($this->fakeDrive, $companyB);

    expect($folderA)->not->toBe($folderB);

    $this->fakeDrive->putExternalFile('a-only.pdf', 'application/pdf', 'company a bytes', $folderA);
    $this->fakeDrive->putExternalFile('b-only.pdf', 'application/pdf', 'company b bytes', $folderB);

    $this->ingestionService->discover($companyA);
    $this->ingestionService->discover($companyB);

    $ingestionsA = DriveIngestion::query()->forCompany($companyA->id)->get();
    $ingestionsB = DriveIngestion::query()->forCompany($companyB->id)->get();

    expect($ingestionsA)->toHaveCount(1)
        ->and($ingestionsA->first()->filename)->toBe('a-only.pdf')
        ->and($ingestionsB)->toHaveCount(1)
        ->and($ingestionsB->first()->filename)->toBe('b-only.pdf');
});

it('registers a downloaded ingestion as a new Document with drive_import provenance and an audit row, linking document_id back', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $folderId = inboundFolderIdFor($this->fakeDrive, $company);
    $this->fakeDrive->putExternalFile('to-register.pdf', 'application/pdf', '%PDF-1.4 register me', $folderId);

    $this->ingestionService->discover($company);
    $ingestion = DriveIngestion::query()->forCompany($company->id)->first();

    $this->ingestionService->download($ingestion);
    $ingestion->refresh();
    expect($ingestion->status)->toBe(DriveIngestionStatus::Downloaded);

    $this->ingestionService->register($ingestion);
    $ingestion->refresh();

    expect($ingestion->status)->toBe(DriveIngestionStatus::Registered)
        ->and($ingestion->document_id)->not->toBeNull();

    $document = $ingestion->document;

    expect($document)->not->toBeNull()
        ->and($document->company_id)->toBe($company->id)
        ->and($document->creator_id)->toBeNull()
        ->and($document->currentVersion->checksum_sha256)->toBe($ingestion->checksum_sha256);

    $uploadedAudit = $document->audits()->where('action', 'uploaded')->first();
    expect($uploadedAudit->metadata['source'])->toBe('drive_import');

    $importedAudit = $document->audits()->where('action', 'drive_imported')->first();
    expect($importedAudit)->not->toBeNull()
        ->and($importedAudit->metadata['drive_ingestion_id'])->toBe($ingestion->id)
        ->and($importedAudit->metadata['drive_file_id'])->toBe($ingestion->drive_file_id);
});
