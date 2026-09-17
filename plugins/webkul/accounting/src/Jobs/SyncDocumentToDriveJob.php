<?php

namespace Webkul\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DriveSyncService;

/**
 * Aureus document operations never depend on this succeeding -- by the
 * time this job runs, DocumentService::upload()/addVersion() already
 * committed. A failure here only ever affects DocumentDriveSync's own
 * status (Failed, retryable), never the document itself.
 */
class SyncDocumentToDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 3600];
    }

    public function __construct(public readonly int $documentId) {}

    public function handle(DriveSyncService $driveSyncService): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            // Deleted between dispatch and execution -- nothing to sync.
            return;
        }

        $driveSyncService->export($document);
    }

    /**
     * Called once, after $tries is exhausted -- not on every individual
     * retry. This is the definitive "we gave up" audit entry;
     * DriveSyncService::export() already records the Failed status +
     * error message on every attempt, retried or not, so the UI stays
     * accurate throughout without an audit row per attempt.
     */
    public function failed(Throwable $exception): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        $document->audits()->create([
            'company_id' => $document->company_id,
            'actor_id'   => null,
            'action'     => DocumentAuditAction::DriveSyncFailed,
            'metadata'   => [
                'error'    => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ],
        ]);
    }
}
