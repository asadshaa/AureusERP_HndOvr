<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource\Pages\ListDocuments;
use Webkul\Accounting\Jobs\SyncDocumentToDriveJob;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DriveSyncService;
use Webkul\Accounting\Support\AccountingPermissions;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

use Webkul\Accounting\Tests\Helpers\FakeDriveClient;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Storage::fake('accounting_documents');
    Config::set('accounting_drive.enabled', true);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    app()->instance(DriveClient::class, new FakeDriveClient);

    $this->service = app(DocumentService::class);
});

it('hides the Sync now action for a user without manage-documents permission', function () {
    $uploader = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);
    $viewer = documentTestUser(Company::find($uploader->default_company_id), [AccountingPermissions::ViewDocuments]);

    $document = $this->service->upload(
        $uploader, $uploader->default_company_id, DocumentType::Other, 'Permission check', null,
        fakeUploadedFileWithRealContent('perm.pdf', 'application/pdf'),
    );

    test()->actingAs($viewer);

    Livewire::test(ListDocuments::class)->assertTableActionHidden('syncToDrive', $document);
});

it('shows the Sync now action for a user with manage-documents permission, and dispatches the job when clicked', function () {
    Queue::fake();

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Sync action check', null,
        fakeUploadedFileWithRealContent('sync-action.pdf', 'application/pdf'),
    );

    test()->actingAs($user);

    Livewire::test(ListDocuments::class)
        ->assertTableActionVisible('syncToDrive', $document)
        ->callTableAction('syncToDrive', $document);

    Queue::assertPushed(SyncDocumentToDriveJob::class, fn (SyncDocumentToDriveJob $job) => $job->documentId === $document->id);
});

it('hides Drive status entirely when Drive sync is disabled', function () {
    Config::set('accounting_drive.enabled', false);

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments, AccountingPermissions::ViewDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Disabled UI check', null,
        fakeUploadedFileWithRealContent('disabled-ui.pdf', 'application/pdf'),
    );

    test()->actingAs($user);

    Livewire::test(ListDocuments::class)
        ->assertTableActionHidden('syncToDrive', $document)
        ->assertTableActionHidden('openInDrive', $document);
});

it('the queued job actually performs the export when it runs', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Job execution check', null,
        fakeUploadedFileWithRealContent('job.pdf', 'application/pdf'),
    );

    (new SyncDocumentToDriveJob($document->id))->handle(app(DriveSyncService::class));

    expect($document->driveSync()->first()->status)->toBe(DriveSyncStatus::Synced);
});

it('does nothing (no error) when the job runs for a document that was since deleted', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Deleted before job runs', null,
        fakeUploadedFileWithRealContent('deleted.pdf', 'application/pdf'),
    );
    $documentId = $document->id;
    $document->forceDelete();

    (new SyncDocumentToDriveJob($documentId))->handle(app(DriveSyncService::class));

    expect(true)->toBeTrue(); // Reaching here without an exception is the assertion.
});
