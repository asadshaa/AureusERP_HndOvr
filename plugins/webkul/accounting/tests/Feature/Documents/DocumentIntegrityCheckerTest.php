<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentIntegrityChecker;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);
    $this->checker = app(DocumentIntegrityChecker::class);
});

it('reports a clean company as having no missing or orphaned objects', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );

    $report = $this->checker->check($user->default_company_id);

    expect($report['versions_checked'])->toBe(1)
        ->and($report['missing_objects'])->toBe([])
        ->and($report['orphan_files'])->toBe([]);
});

it('detects a version whose storage object was deleted outside the application', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );
    $version = $document->currentVersion;

    // Simulate the file being lost/deleted directly on the disk, bypassing
    // DocumentService entirely -- exactly the scenario this exists to catch.
    Storage::disk('accounting_documents')->delete($version->storage_path);

    $report = $this->checker->check($user->default_company_id);

    expect($report['missing_objects'])->toHaveCount(1)
        ->and($report['missing_objects'][0]['version_id'])->toBe($version->id)
        ->and($report['missing_objects'][0]['storage_path'])->toBe($version->storage_path)
        ->and($report['orphan_files'])->toBe([]);
});

it('detects a storage object with no matching document version record', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    // A real, tracked document, so the report proves it's telling the
    // orphan apart from legitimate objects rather than flagging everything.
    $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );

    $orphanPath = "companies/{$user->default_company_id}/".now()->format('Y').'/documents/orphan-test.pdf';
    Storage::disk('accounting_documents')->put($orphanPath, 'nobody points at this file');

    $report = $this->checker->check($user->default_company_id);

    expect($report['missing_objects'])->toBe([])
        ->and($report['orphan_files'])->toContain($orphanPath);
});

it('scopes the check to one company when a company id is given', function () {
    $userA = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $userB = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $this->service->upload(
        $userA, $userA->default_company_id, DocumentType::Receipt, 'Company A receipt', null,
        fakeUploadedFileWithRealContent('a.pdf', 'application/pdf'),
    );
    $this->service->upload(
        $userB, $userB->default_company_id, DocumentType::Receipt, 'Company B receipt', null,
        fakeUploadedFileWithRealContent('b.pdf', 'application/pdf'),
    );

    $report = $this->checker->check($userA->default_company_id);

    expect($report['versions_checked'])->toBe(1);
});
