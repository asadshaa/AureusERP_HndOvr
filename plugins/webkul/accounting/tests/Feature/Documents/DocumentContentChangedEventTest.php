<?php

/**
 * Tests the DocumentService <-> Drive-sync wiring itself, not the export
 * logic (that's DriveSyncExportTest). Two things worth proving
 * separately:
 *
 *  - DocumentService fires DocumentContentChanged on every successful
 *    upload/addVersion, unconditionally -- it has no idea whether Drive
 *    sync is enabled or even exists.
 *  - When nothing is listening (accounting_drive.enabled=false, the real
 *    default -- AccountingServiceProvider only registers the listener at
 *    BOOT time, so toggling config mid-test can't retroactively
 *    register/unregister it), firing that event costs nothing: no job on
 *    the queue, no exception, nothing.
 *
 * The listener's own logic (event -> job dispatch) is exercised directly
 * against Event::fake() below, rather than relying on the service
 * provider's boot-time registration, for exactly that reason.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Events\DocumentContentChanged;
use Webkul\Accounting\Jobs\SyncDocumentToDriveJob;
use Webkul\Accounting\Listeners\DispatchDriveSyncOnDocumentChanged;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);
});

it('fires DocumentContentChanged on upload, unconditionally', function () {
    Event::fake([DocumentContentChanged::class]);

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Event check', null,
        fakeUploadedFileWithRealContent('event.pdf', 'application/pdf'),
    );

    Event::assertDispatched(DocumentContentChanged::class, fn (DocumentContentChanged $event) => $event->document->id === $document->id);
});

it('fires DocumentContentChanged on addVersion, unconditionally', function () {
    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Event check v2', null,
        fakeUploadedFileWithRealContent('event-v1.pdf', 'application/pdf'),
    );

    Event::fake([DocumentContentChanged::class]);

    $this->service->addVersion(
        $user, $document,
        fakeUploadedFileWithRealContent('event-v2.pdf', 'application/pdf'),
        changeReason: 'Testing the event fires on new versions too',
    );

    Event::assertDispatched(DocumentContentChanged::class);
});

it('costs nothing when nothing is listening -- no job queued, no error, with Drive sync off (the real default)', function () {
    Queue::fake();

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);

    // No Event::listen() registered here at all -- this is the actual
    // default state (accounting_drive.enabled=false in .env, so
    // AccountingServiceProvider never registered the listener at boot).
    $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'No listener check', null,
        fakeUploadedFileWithRealContent('no-listener.pdf', 'application/pdf'),
    );

    Queue::assertNothingPushed();
});

it('the listener itself dispatches SyncDocumentToDriveJob for the event\'s document', function () {
    Queue::fake();
    Event::listen(DocumentContentChanged::class, DispatchDriveSyncOnDocumentChanged::class);

    $user = documentTestUser(permissions: [AccountingPermissions::ManageDocuments]);
    $document = $this->service->upload(
        $user, $user->default_company_id, DocumentType::Other, 'Listener logic check', null,
        fakeUploadedFileWithRealContent('listener.pdf', 'application/pdf'),
    );

    Queue::assertPushed(SyncDocumentToDriveJob::class, fn (SyncDocumentToDriveJob $job) => $job->documentId === $document->id);
});
