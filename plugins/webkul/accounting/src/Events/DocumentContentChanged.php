<?php

namespace Webkul\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webkul\Accounting\Models\Document;

/**
 * Fired by DocumentService after a successful upload() or addVersion() --
 * "this document's current content just changed, in case anything wants
 * to react." DocumentService has no idea Drive sync exists; it just
 * fires this and moves on. DispatchDriveSyncOnDocumentChanged is the
 * only listener today, registered only when accounting_drive.enabled is
 * true, but nothing about this event is Drive-specific -- any other
 * future reaction to "a document's content changed" hangs off this same
 * event instead of DocumentService growing another bespoke hook.
 */
class DocumentContentChanged
{
    use Dispatchable;

    public function __construct(public readonly Document $document) {}
}
