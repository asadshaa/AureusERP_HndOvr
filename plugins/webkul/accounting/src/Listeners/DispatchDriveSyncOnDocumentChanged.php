<?php

namespace Webkul\Accounting\Listeners;

use Webkul\Accounting\Events\DocumentContentChanged;
use Webkul\Accounting\Jobs\SyncDocumentToDriveJob;

/**
 * The only place accounting_drive.enabled is checked before anything
 * Drive-related happens at all -- when it's false, this listener isn't
 * even registered (see AccountingServiceProvider), so a
 * DocumentContentChanged event costs nothing beyond firing.
 */
class DispatchDriveSyncOnDocumentChanged
{
    public function handle(DocumentContentChanged $event): void
    {
        SyncDocumentToDriveJob::dispatch($event->document->id);
    }
}
