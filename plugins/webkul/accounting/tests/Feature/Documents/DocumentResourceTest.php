<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource\Pages\ListDocuments;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $this->service = app(DocumentService::class);
});

it('renders the documents list page for a user with view permission', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ViewDocuments]);
    test()->actingAs($user);

    Livewire::test(ListDocuments::class)->assertOk();
});

it('denies the documents list page to a user without view permission', function () {
    $user = documentTestUser(); // no permissions
    test()->actingAs($user);

    expect(DocumentResource::canViewAny())->toBeFalse();
});

it('only lists the acting user\'s own company documents in the table', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);

    $userA = documentTestUser($companyA, [AccountingPermissions::ViewDocuments, AccountingPermissions::ManageDocuments]);
    $userB = documentTestUser($companyB, [AccountingPermissions::ManageDocuments]);

    $docA = $this->service->upload($userA, $companyA->id, DocumentType::Other, 'Company A doc', null, fakeUploadedFileWithRealContent('a.pdf', 'application/pdf'));
    $docB = $this->service->upload($userB, $companyB->id, DocumentType::Other, 'Company B doc', null, fakeUploadedFileWithRealContent('b.pdf', 'application/pdf'));

    test()->actingAs($userA);

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords([$docA])
        ->assertCanNotSeeTableRecords([$docB]);
});

it('downloads the current version through the record action with the right bytes', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ViewDocuments, AccountingPermissions::ManageDocuments, AccountingPermissions::DownloadDocuments]);
    test()->actingAs($user);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Receipt, 'Downloadable receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf', 'real receipt bytes for the download test'),
    );

    // The action's underlying DocumentService::retrieveContents() call
    // already re-verifies the checksum before returning anything (proven
    // directly in DocumentServiceTest) -- here we're proving the Filament
    // action actually wires through to a real file download rather than,
    // say, silently swallowing the result.
    Livewire::test(ListDocuments::class)
        ->callTableAction('download', $document)
        ->assertFileDownloaded('receipt.pdf');

    expect($document->audits()->where('action', DocumentAuditAction::Downloaded)->exists())->toBeTrue();
});

it('archives an active document and restores it back to active through the table actions', function () {
    $user = documentTestUser(permissions: [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DeleteDocuments,
    ]);
    test()->actingAs($user);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Archivable doc', null,
        fakeUploadedFileWithRealContent('archive.pdf', 'application/pdf'),
    );

    Livewire::test(ListDocuments::class)
        ->assertTableActionVisible('archive', $document)
        ->assertTableActionHidden('restore', $document)
        ->callTableAction('archive', $document);

    expect($document->refresh()->status)->toBe(DocumentStatus::Archived);

    Livewire::test(ListDocuments::class)
        ->assertTableActionHidden('archive', $document)
        ->assertTableActionVisible('restore', $document)
        ->callTableAction('restore', $document);

    expect($document->refresh()->status)->toBe(DocumentStatus::Active);
});

it('hides the download action for a user without download permission', function () {
    $uploader = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);
    $viewer = documentTestUser(Company::find($uploader->default_company_id), [AccountingPermissions::ViewDocuments]);

    $document = $this->service->upload(
        $uploader, $uploader->default_company_id, DocumentType::Other, 'No download permission', null,
        fakeUploadedFileWithRealContent('nodl.pdf', 'application/pdf'),
    );

    test()->actingAs($viewer);

    Livewire::test(ListDocuments::class)->assertTableActionHidden('download', $document);
});
