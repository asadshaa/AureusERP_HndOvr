<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);
});

it('uploads a document, records the first version with a real checksum, and audits the upload', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $file = fakeUploadedFileWithRealContent('invoice-scan.pdf', 'application/pdf');

    $document = $this->service->upload(
        $user,
        $user->default_company_id,
        DocumentType::Invoice,
        'September freight invoice',
        'Scanned copy from the customer',
        $file,
        ipAddress: '10.0.0.5',
    );

    expect($document->exists)->toBeTrue()
        ->and($document->company_id)->toBe($user->default_company_id)
        ->and($document->creator_id)->toBe($user->id)
        ->and($document->document_type)->toBe(DocumentType::Invoice)
        ->and($document->status)->toBe(DocumentStatus::Active)
        ->and($document->current_version_id)->not->toBeNull();

    $version = $document->currentVersion;
    expect($version->version_number)->toBe(1)
        ->and($version->original_filename)->toBe('invoice-scan.pdf')
        ->and($version->mime_type)->toBe('application/pdf')
        ->and($version->file_size)->toBeGreaterThan(0)
        ->and($version->checksum_sha256)->toHaveLength(64);

    expect(Storage::disk('accounting_documents')->exists($version->storage_path))->toBeTrue();

    $audit = $document->audits()->first();
    expect($audit->action)->toBe(DocumentAuditAction::Uploaded)
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->ip_address)->toBe('10.0.0.5');
});

it('adds a new version without touching or deleting the previous one', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt-v1.pdf', 'application/pdf'),
    );

    $firstVersion = $document->currentVersion;
    $firstPath = $firstVersion->storage_path;

    $secondVersion = $this->service->addVersion(
        $user, $document,
        fakeUploadedFileWithRealContent('receipt-v2.pdf', 'application/pdf'),
        changeReason: 'Original scan was blurry',
    );

    $document->refresh();

    expect($document->current_version_id)->toBe($secondVersion->id)
        ->and($secondVersion->version_number)->toBe(2)
        ->and($secondVersion->change_reason)->toBe('Original scan was blurry')
        ->and($document->versions()->count())->toBe(2);

    // The first version's file is still there, untouched.
    expect(Storage::disk('accounting_documents')->exists($firstPath))->toBeTrue()
        ->and(Storage::disk('accounting_documents')->get($firstPath))->not->toBeEmpty();

    expect($document->audits()->where('action', DocumentAuditAction::VersionAdded)->exists())->toBeTrue();
});

it('rejects a file type that is not on the allowed list, with a plain-English reason', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $file = fakeUploadedFileWithRealContent('malware.exe', 'application/x-msdownload');

    expect(fn () => $this->service->upload($user, $user->default_company_id, DocumentType::Other, 'Suspicious file', null, $file))
        ->toThrow(RuntimeException::class, "isn't an accepted file type");

    expect(Document::query()->count())->toBe(0);
});

it('rejects a file larger than the configured limit', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $tooBig = UploadedFile::fake()->create('huge.pdf', (DocumentService::MAX_FILE_SIZE_BYTES / 1024) + 100, 'application/pdf');

    expect(fn () => $this->service->upload($user, $user->default_company_id, DocumentType::Other, 'Huge file', null, $tooBig))
        ->toThrow(RuntimeException::class, 'larger than the');

    expect(Document::query()->count())->toBe(0);
});

it('attaches a document to a same-company record and audits it', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $company = Company::find($user->default_company_id);

    $document = $this->service->upload(
        $user, $company->id, DocumentType::Invoice, 'Invoice evidence', null,
        fakeUploadedFileWithRealContent('doc.pdf', 'application/pdf'),
    );

    // Any model with a company_id column works here -- attach() only cares
    // that company_id matches, not the model type. Using a second Document
    // as the "attachable" avoids pulling in an unrelated accounting model
    // just for this generic test; real invoice/bill/journal attachment is
    // Phase 4's job.
    $recordInSameCompany = $this->service->upload(
        $user, $company->id, DocumentType::Other, 'The record being attached to', null,
        fakeUploadedFileWithRealContent('record.pdf', 'application/pdf'),
    );

    $attachment = $this->service->attach($user, $document, $recordInSameCompany, note: 'Primary supporting document');

    expect($attachment->exists)->toBeTrue()
        ->and($attachment->document_id)->toBe($document->id)
        ->and($attachment->attachable_type)->toBe(Document::class)
        ->and($attachment->attachable_id)->toBe($recordInSameCompany->id)
        ->and($attachment->note)->toBe('Primary supporting document');

    expect($document->audits()->where('action', DocumentAuditAction::Attached)->exists())->toBeTrue();

    $this->service->detach($user, $attachment);

    expect($document->refresh()->attachments()->count())->toBe(0)
        ->and($document->audits()->where('action', DocumentAuditAction::Detached)->exists())->toBeTrue();
});

it('retrieves the current version\'s exact bytes and verifies the checksum on every read', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::DownloadDocuments, AccountingPermissions::ViewDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Toll receipt', null,
        fakeUploadedFileWithRealContent('toll.pdf', 'application/pdf'),
    );

    $result = $this->service->retrieveContents($user, $document->id, ipAddress: '10.0.0.9');

    expect($result['document']->id)->toBe($document->id)
        ->and($result['version']->id)->toBe($document->current_version_id)
        ->and(hash('sha256', $result['contents']))->toBe($result['version']->checksum_sha256);

    $audit = $document->audits()->where('action', DocumentAuditAction::Downloaded)->first();
    expect($audit)->not->toBeNull()->and($audit->ip_address)->toBe('10.0.0.9');
});

it('refuses to return a file whose storage object has gone missing', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::DownloadDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Missing object test', null,
        fakeUploadedFileWithRealContent('gone.pdf', 'application/pdf'),
    );

    Storage::disk('accounting_documents')->delete($document->currentVersion->storage_path);

    expect(fn () => $this->service->retrieveContents($user, $document->id))
        ->toThrow(RuntimeException::class, 'missing from storage');

    expect($document->audits()->where('action', DocumentAuditAction::AccessDenied)->exists())->toBeTrue();
});

it('refuses to return a file whose bytes no longer match its recorded checksum', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::DownloadDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Tampered file test', null,
        fakeUploadedFileWithRealContent('tampered.pdf', 'application/pdf'),
    );

    Storage::disk('accounting_documents')->put($document->currentVersion->storage_path, 'this content was altered outside the app');

    expect(fn () => $this->service->retrieveContents($user, $document->id))
        ->toThrow(RuntimeException::class, 'does not match its recorded checksum');

    expect($document->audits()->where('action', DocumentAuditAction::AccessDenied)->exists())->toBeTrue();
});

it('archives and restores a document, auditing both transitions', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::DeleteDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'To be archived', null,
        fakeUploadedFileWithRealContent('archive-me.pdf', 'application/pdf'),
    );

    $this->service->archive($user, $document);
    expect($document->refresh()->status)->toBe(DocumentStatus::Archived);

    $this->service->restore($user, $document);
    expect($document->refresh()->status)->toBe(DocumentStatus::Active);

    expect($document->audits()->where('action', DocumentAuditAction::Archived)->exists())->toBeTrue()
        ->and($document->audits()->where('action', DocumentAuditAction::Restored)->exists())->toBeTrue();
});

it('refuses to upload without the manage-documents permission', function () {
    $user = documentTestUser(); // no permissions granted

    expect(fn () => $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'No permission', null,
        fakeUploadedFileWithRealContent('x.pdf', 'application/pdf'),
    ))->toThrow(RuntimeException::class, "don't have permission");

    expect(Document::query()->count())->toBe(0);
});

it('refuses to download without the download-documents permission even within the user\'s own company', function () {
    $uploader = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $viewerWithoutDownload = documentTestUser(Company::find($uploader->default_company_id), permissions: []);

    $document = $this->service->upload(
        $uploader, $uploader->default_company_id, DocumentType::Other, 'Restricted download', null,
        fakeUploadedFileWithRealContent('restricted.pdf', 'application/pdf'),
    );

    expect(fn () => $this->service->retrieveContents($viewerWithoutDownload, $document->id))
        ->toThrow(RuntimeException::class, "don't have permission");
});
