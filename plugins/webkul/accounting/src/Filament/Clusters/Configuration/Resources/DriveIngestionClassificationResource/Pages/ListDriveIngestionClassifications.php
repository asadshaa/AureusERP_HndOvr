<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Services\Drive\DriveClassificationService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

class ListDriveIngestionClassifications extends ListRecords
{
    protected static string $resource = DriveIngestionClassificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncDriveNow')
                ->label('Sync Drive now')
                ->icon('heroicon-o-arrow-path')
                // NOT ViewDocuments: this action calls the live Drive API
                // and writes DriveIngestion/Document rows -- it is not
                // read-only, so it cannot be gated on the same passive
                // view permission the (read-only) resource itself uses for
                // canViewAny(). ManageDocuments is the write-capable
                // permission this plugin already uses for document
                // mutation, and -- critically -- it is granted to none of
                // the read-only audit roles (internalAuditor()/
                // externalAuditor() only ever grant ViewDocuments/
                // DownloadDocuments), matching the established pattern of
                // gating a manual mutating action on a distinct
                // action-level permission from the passive view one (e.g.
                // ListBankTransactionMappings's "Run priority matching"/
                // "Detect transfers" on ReviewBankTransactions, not
                // BankTransactions).
                ->authorize(AccountingPermissions::ManageDocuments)
                ->action(fn () => $this->syncDriveNow()),
        ];
    }

    /**
     * Manual, synchronous trigger for the same
     * DriveIngestionService::syncInbound() (discover() + download() +
     * register(), Cache-locked per company) the scheduled
     * accounting:drive:sync-inbound command's job calls, just for one
     * company (the acting user's own, via default_company_id -- the exact
     * company-scoping mechanism every other manual action in this plugin
     * uses, e.g. ListBankTransactionMappings's "Run priority matching"/
     * "Detect transfers" actions) and inline rather than queued, so the
     * click gets immediate feedback. syncInbound() only ever touches
     * Drive's inbound folder for the ONE company passed to it -- there is
     * no way for this call to see or affect another company's data. Its
     * own per-company lock also means this call safely waits its turn
     * rather than racing a concurrently-running scheduled job for the
     * same company.
     */
    private function syncDriveNow(): void
    {
        if (! config('accounting_drive.enabled')) {
            Notification::make()->danger()->title('Google Drive sync is not enabled')
                ->body('accounting_drive.enabled is off for this installation -- nothing to sync.')
                ->send();

            return;
        }

        $companyId = Auth::user()?->default_company_id;
        $company = $companyId ? Company::query()->find($companyId) : null;

        if (! $company) {
            Notification::make()->danger()->title('No company to sync')
                ->body('Your account has no default company set.')
                ->send();

            return;
        }

        try {
            $touched = app(DriveIngestionService::class)->syncInbound($company);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Drive sync failed')
                ->body($e->getMessage())
                ->send();

            return;
        }

        $counts = [
            'new'              => 0,
            'already_synced'   => 0,
            'duplicate'        => 0,
            'failed'           => 0,
        ];

        /** @var DriveIngestion $ingestion */
        foreach ($touched as $ingestion) {
            // Exhaustive over all 7 DriveIngestionStatus cases (no
            // default arm) so this breakdown can never silently drop a
            // status and undercount relative to the "N file(s) found"
            // headline below -- Discovered/Downloading/Downloaded are
            // grouped with Registered under "new" because syncInbound()
            // chains download()+register() for every one of them, so
            // besides Registered (fully processed) they only mean "still
            // being processed" or an in-flight state that never got as
            // far as Failed, not "nothing happened".
            match ($ingestion->status) {
                DriveIngestionStatus::Discovered,
                DriveIngestionStatus::Downloading,
                DriveIngestionStatus::Downloaded,
                DriveIngestionStatus::Registered                => $counts['new']++,
                DriveIngestionStatus::DuplicateSkipped          => $counts['already_synced']++,
                DriveIngestionStatus::RecognizedInternalOrigin  => $counts['duplicate']++,
                DriveIngestionStatus::Failed                    => $counts['failed']++,
            };
        }

        $classifier = app(DriveClassificationService::class);
        foreach ($touched as $ingestion) {
            if ($ingestion->status === DriveIngestionStatus::Registered) {
                try {
                    $classifier->classify($ingestion);
                } catch (Throwable) {
                    // Handled and logged inside classify()
                }
            }
        }

        Notification::make()->success()
            ->title(count($touched).' file(s) found in the Drive inbound folder')
            ->body(
                "New documents: {$counts['new']}; already synchronized: {$counts['already_synced']}; ".
                "duplicates (Aureus-originated): {$counts['duplicate']}; failed: {$counts['failed']}."
            )
            ->send();
    }
}
