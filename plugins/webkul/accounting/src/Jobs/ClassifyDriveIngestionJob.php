<?php

namespace Webkul\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Services\Drive\DriveClassificationService;

/**
 * Phase 2's async entry point: dispatched by
 * DriveIngestionService::register() right after a DriveIngestion reaches
 * Registered. Mirrors DiscoverDriveIngestionsJob's style -- a failure
 * here only ever affects this ingestion's own classification row, never
 * the Document that already exists.
 */
class ClassifyDriveIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 3600];
    }

    public function __construct(public readonly int $driveIngestionId) {}

    public function handle(DriveClassificationService $classifier): void
    {
        $ingestion = DriveIngestion::query()->find($this->driveIngestionId);

        if (! $ingestion) {
            return;
        }

        $classifier->classify($ingestion);
    }
}
