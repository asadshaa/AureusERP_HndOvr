<?php

namespace Webkul\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Jobs\DiscoverDriveIngestionsJob;
use Webkul\Support\Models\Company;

/**
 * Dispatches Phase 1 Drive -> Aureus discovery (DiscoverDriveIngestionsJob)
 * for one company or every company, the same way export-direction sync is
 * driven per-document by SyncDocumentToDriveJob -- this is the equivalent
 * entry point for the import direction. Not scheduled anywhere yet
 * (routes/console.php has no Schedule:: entries at all); wiring a
 * recurring run is a separate, later decision.
 */
class SyncDriveIngestionsCommand extends Command
{
    protected $signature = 'accounting:drive:sync-inbound {--company= : A specific company id; omit to run for every Drive-enabled company}';

    protected $description = 'Discover files dropped in each company\'s Google Drive inbound folder (Phase 1: discovery + dedup only)';

    public function handle(): int
    {
        if (! config('accounting_drive.enabled')) {
            $this->error('Google Drive sync is not enabled for this installation (accounting_drive.enabled is false).');

            return self::FAILURE;
        }

        $companyOption = $this->option('company');

        // "Enabled" for Drive sync purposes is installation-wide
        // (accounting_drive.enabled, checked above) -- there is no
        // per-company opt-in flag anywhere else in this feature (export's
        // own DriveSyncService/DispatchDriveSyncOnDocumentChanged gate on
        // that exact same single config flag), so every active company is
        // in scope once the feature itself is enabled.
        $companies = $companyOption
            ? Company::query()->where('id', $companyOption)->get()
            : Company::query()->where('is_active', true)->get();

        if ($companies->isEmpty()) {
            $this->error($companyOption
                ? "No company found with id {$companyOption}."
                : 'No active companies found.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            DiscoverDriveIngestionsJob::dispatch($company->id);
            $this->info("Dispatched Drive inbound discovery for company #{$company->id} ({$company->name}).");
        }

        return self::SUCCESS;
    }
}
