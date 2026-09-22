<?php

namespace Webkul\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Support\Models\Company;

/**
 * The import-direction mirror of SyncDocumentToDriveJob: drives discovery
 * (Phase 1) through registration, which is what actually dispatches
 * ClassifyDriveIngestionJob (Phase 2) -- via
 * DriveIngestionService::syncInbound(), not discover() alone, or nothing
 * would ever progress past Discovered status. Never touches invoice
 * creation, FS Tag resolution, GL posting or approval routing itself,
 * though -- that stays downstream, in later phases' own jobs/services. A
 * failure here only ever affects DriveIngestion rows' own status (Failed,
 * retryable on the next pass), never an Aureus document that already
 * exists.
 */
class DiscoverDriveIngestionsJob implements ShouldQueue
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

    public function __construct(public readonly int $companyId) {}

    public function handle(DriveIngestionService $ingestionService): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company) {
            // Deleted between dispatch and execution -- nothing to discover.
            return;
        }

        $ingestionService->syncInbound($company);
    }
}
