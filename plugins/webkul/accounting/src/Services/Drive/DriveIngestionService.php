<?php

namespace Webkul\Accounting\Services\Drive;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Jobs\ClassifyDriveIngestionJob;
use Webkul\Accounting\Models\DocumentDriveSync;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\DriveFolderPathResolver;
use Webkul\Support\Models\Company;

/**
 * Phase 1 of Drive -> Aureus ingestion: discovery + dedup + review record
 * only. Deliberately stops short of invoice creation, FS Tag resolution,
 * GL posting or approval routing -- register() produces a plain Document
 * (DocumentType::Other) with a Drive-import provenance marker, and
 * nothing here decides what that document IS beyond that.
 *
 * Mirrors DriveSyncService's role for the opposite direction: this is one
 * of only two classes allowed to hold a DriveClient reference.
 */
class DriveIngestionService
{
    public function __construct(
        private readonly DriveClient $drive,
        private readonly DriveFolderPathResolver $pathResolver,
        private readonly DocumentService $documents,
    ) {}

    /**
     * Lists $company's inbound Drive folder (found-or-created, same as
     * export's resolveLeafFolder()) and, for every file found, either:
     *
     *   a. marks it RecognizedInternalOrigin if Aureus itself exported it
     *      (a DocumentDriveSync row already references this drive_file_id)
     *      -- the bidirectional-loop-prevention check, run FIRST, before
     *      any other dedup logic;
     *   b. skips it if an ingestion row already exists for (company,
     *      drive_file_id) with the same checksum -- already discovered,
     *      nothing changed;
     *   c. marks it for reprocessing if an ingestion row exists but the
     *      checksum differs -- the file changed on Drive since we last
     *      looked;
     *   d. otherwise creates a new Discovered row.
     *
     * @return array<int, DriveIngestion> every ingestion row touched by this call
     */
    public function discover(Company $company): array
    {
        $folderId = $this->resolveInboundFolder($company);

        $touched = [];

        foreach ($this->drive->listFiles($folderId) as $file) {
            try {
                $touched[] = $this->discoverOne($company, $folderId, $file);
            } catch (Throwable $e) {
                Log::error('accounting.drive_ingestion.discover_file_failed', [
                    'company_id'    => $company->id,
                    'drive_file_id' => $file['id'] ?? null,
                    'error'         => $e->getMessage(),
                ]);

                $diagnostic = DriveErrorFormatter::format('Discovery', $e);

                $failedRow = DriveIngestion::query()->updateOrCreate(
                    [
                        'company_id'    => $company->id,
                        'drive_file_id' => $file['id'] ?? 'unknown_'.uniqid(),
                    ],
                    [
                        'drive_folder_id'   => $folderId,
                        'filename'          => $file['name'] ?? 'unknown',
                        'mime_type'         => $file['mimeType'] ?? 'application/octet-stream',
                        'file_size'         => (int) ($file['size'] ?? 0),
                        'drive_modified_at' => $this->parseModifiedTime($file['modifiedTime'] ?? null),
                        'checksum_sha256'   => '',
                        'status'            => DriveIngestionStatus::Failed,
                        'failure_reason'    => json_encode($diagnostic),
                        'discovered_at'     => now(),
                        'processed_at'      => now(),
                    ]
                );

                $touched[] = $failedRow;
            }
        }

        return $touched;
    }

    /**
     * The real production entry point -- both the scheduled job
     * (DiscoverDriveIngestionsJob) and the manual "Sync Now" Filament
     * action must call this, not discover() alone. discover() by itself
     * only ever leaves a row at Discovered/DuplicateSkipped/
     * RecognizedInternalOrigin/Failed; nothing downstream (classification,
     * approval, posting, bank matching) can ever run for a file until
     * register() -- which is what dispatches ClassifyDriveIngestionJob --
     * is also called. This method does that: discover(), then
     * download()+register() every row discover() left at Discovered.
     *
     * Locked per company for the whole discover()+download()+register()
     * pass, the same Cache::lock pattern DriveSyncService::export() uses
     * for the opposite (export) direction -- so the scheduled job and a
     * manual click for the same company can never run this concurrently
     * and race on the same DriveIngestion rows (discoverOne()'s
     * check-then-create is not otherwise safe against that).
     *
     * @return array<int, DriveIngestion> every ingestion row touched by discover(), refreshed to its final status
     */
    public function syncInbound(Company $company): array
    {
        $lock = Cache::lock("accounting-drive-ingestion:company:{$company->id}", 60);

        return $lock->block(15, function () use ($company): array {
            $touched = $this->discover($company);

            foreach ($touched as $ingestion) {
                if ($ingestion->status !== DriveIngestionStatus::Discovered) {
                    continue;
                }

                try {
                    $this->download($ingestion);
                    $ingestion->refresh();

                    if ($ingestion->status === DriveIngestionStatus::Downloaded) {
                        $this->register($ingestion);
                        $ingestion->refresh();
                    }
                } catch (Throwable $e) {
                    Log::error('accounting.drive_ingestion.sync_item_failed', [
                        'ingestion_id' => $ingestion->id,
                        'error'        => $e->getMessage(),
                    ]);

                    $diagnostic = DriveErrorFormatter::format('Download', $e);
                    $ingestion->update([
                        'status'         => DriveIngestionStatus::Failed,
                        'failure_reason' => json_encode($diagnostic),
                        'processed_at'   => now(),
                    ]);
                }
            }

            return $touched;
        });
    }

    private function discoverOne(Company $company, string $folderId, array $file): DriveIngestion
    {
        // (a) Loop-prevention check FIRST -- a file Aureus itself exported
        // must never be re-ingested as though it were new external input,
        // no matter what the dedup checks below would otherwise decide.
        $isInternalOrigin = DocumentDriveSync::query()
            ->where('drive_file_id', $file['id'])
            ->exists();

        if ($isInternalOrigin) {
            return DriveIngestion::query()->updateOrCreate(
                ['company_id' => $company->id, 'drive_file_id' => $file['id']],
                [
                    'drive_folder_id'   => $folderId,
                    'filename'          => $file['name'],
                    'mime_type'         => $file['mimeType'],
                    'file_size'         => $file['size'],
                    'drive_modified_at' => $this->parseModifiedTime($file['modifiedTime'] ?? null),
                    // Never recomputed for an internal-origin file: we
                    // already know it's Aureus's own export, so there is
                    // no need to download it just to fill this column.
                    'checksum_sha256'   => $file['checksum_sha256'] ?? '',
                    'status'            => DriveIngestionStatus::RecognizedInternalOrigin,
                    'discovered_at'     => now(),
                    'processed_at'      => now(),
                ],
            );
        }

        $checksum = hash('sha256', $this->drive->downloadFileContent($file['id']));

        $existing = DriveIngestion::query()
            ->forCompany($company->id)
            ->where('drive_file_id', $file['id'])
            ->first();

        if ($existing && $existing->checksum_sha256 === $checksum) {
            // (b) Already discovered, unchanged -- nothing to do. Mark it
            // DuplicateSkipped only if it hadn't already moved further
            // along (Downloaded/Registered); re-discovering a file that's
            // already been registered must not regress its status.
            if ($existing->status === DriveIngestionStatus::Discovered) {
                $existing->update(['status' => DriveIngestionStatus::DuplicateSkipped]);
            }

            return $existing;
        }

        if ($existing) {
            // (c) The file changed on Drive since we last looked.
            // Check if this existing ingestion is already linked to a posted Move/invoice.
            $classification = DriveIngestionClassification::query()
                ->where('drive_ingestion_id', $existing->id)
                ->first();

            $isPosted = $classification && (
                $classification->created_invoice_id !== null ||
                $classification->validation_status === DriveClassificationStatus::Posted
            );

            if ($isPosted) {
                // Post-Posting Drive File Modification Safeguard:
                // An invoice has already been confirmed/posted in Aureus GL for this file.
                // Mutating posted accounting history or creating a duplicate invoice is forbidden.
                // Record an audit warning and keep existing status and document_id intact.
                Log::warning('accounting.drive_ingestion.posted_file_modified_on_drive', [
                    'company_id'         => $company->id,
                    'drive_file_id'      => $file['id'],
                    'created_invoice_id' => $classification->created_invoice_id,
                    'old_checksum'       => $existing->checksum_sha256,
                    'new_checksum'       => $checksum,
                ]);

                $existing->update([
                    'drive_modified_at' => $this->parseModifiedTime($file['modifiedTime'] ?? null),
                    'file_size'         => $file['size'],
                ]);

                return $existing->refresh();
            }

            // Otherwise, file changed before posting -- audit and reset back to Discovered for reprocessing.
            Log::info('accounting.drive_ingestion.checksum_changed', [
                'company_id'      => $company->id,
                'drive_file_id'   => $file['id'],
                'previous_status' => $existing->status->value,
                'old_checksum'    => $existing->checksum_sha256,
                'new_checksum'    => $checksum,
            ]);

            $existing->update([
                'drive_folder_id'   => $folderId,
                'filename'          => $file['name'],
                'mime_type'         => $file['mimeType'],
                'file_size'         => $file['size'],
                'drive_modified_at' => $this->parseModifiedTime($file['modifiedTime'] ?? null),
                'checksum_sha256'   => $checksum,
                'status'            => DriveIngestionStatus::Discovered,
                'document_id'       => null,
                'failure_reason'    => null,
                'discovered_at'     => now(),
                'processed_at'      => null,
            ]);

            return $existing->refresh();
        }

        // (d) Genuinely new.
        return DriveIngestion::query()->create([
            'company_id'        => $company->id,
            'drive_file_id'     => $file['id'],
            'drive_folder_id'   => $folderId,
            'checksum_sha256'   => $checksum,
            'mime_type'         => $file['mimeType'],
            'file_size'         => $file['size'],
            'drive_modified_at' => $this->parseModifiedTime($file['modifiedTime'] ?? null),
            'filename'          => $file['name'],
            'status'            => DriveIngestionStatus::Discovered,
            'discovered_at'     => now(),
        ]);
    }

    /**
     * Re-downloads $ingestion's content and confirms it still matches the
     * checksum recorded at discovery time -- the same defense-in-depth
     * DocumentService::readVerifiedBytes() applies on the export side,
     * just before content from an untrusted external source (a human's
     * Drive folder) is trusted enough to register.
     */
    public function download(DriveIngestion $ingestion): void
    {
        if ($ingestion->status === DriveIngestionStatus::RecognizedInternalOrigin) {
            return;
        }

        $ingestion->update(['status' => DriveIngestionStatus::Downloading]);

        try {
            $contents = $this->drive->downloadFileContent($ingestion->drive_file_id);

            if (hash('sha256', $contents) !== $ingestion->checksum_sha256) {
                $ingestion->update([
                    'status'          => DriveIngestionStatus::Failed,
                    'failure_reason'  => 'Downloaded content does not match the checksum recorded at discovery -- the file may have changed again on Drive mid-download.',
                    'processed_at'    => now(),
                ]);

                return;
            }

            $ingestion->update([
                'status'       => DriveIngestionStatus::Downloaded,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $ingestion->update([
                'status'         => DriveIngestionStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'processed_at'   => now(),
            ]);
        }
    }

    /**
     * Registers a Downloaded ingestion as a new Aureus Document -- via
     * DocumentService::uploadFromPeer(), the same entry point the peer
     * (WebRTC) import direction already uses for content arriving from
     * outside any authenticated User, just with source: 'drive_import'
     * instead of 'peer_transmission'. Idempotent: a row that already has
     * a document_id is left untouched.
     */
    public function register(DriveIngestion $ingestion): void
    {
        if ($ingestion->document_id) {
            return;
        }

        if (! in_array($ingestion->status, [DriveIngestionStatus::Downloaded, DriveIngestionStatus::Discovered], true)) {
            throw new RuntimeException("Drive ingestion #{$ingestion->id} is not ready to be registered (status: {$ingestion->status->value}).");
        }

        $contents = $this->drive->downloadFileContent($ingestion->drive_file_id);

        if (hash('sha256', $contents) !== $ingestion->checksum_sha256) {
            $ingestion->update([
                'status'         => DriveIngestionStatus::Failed,
                'failure_reason' => 'Checksum mismatch at registration time.',
                'processed_at'   => now(),
            ]);

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'drive-ingest-');
        file_put_contents($tmpPath, $contents);

        try {
            $uploadedFile = new UploadedFile($tmpPath, $ingestion->filename, $ingestion->mime_type, null, true);

            $document = $this->documents->uploadFromPeer(
                $ingestion->company_id,
                DocumentType::Other,
                $ingestion->filename,
                'Imported from the Google Drive inbound folder.',
                $uploadedFile,
                source: 'drive_import',
            );

            $document->audits()->create([
                'company_id' => $ingestion->company_id,
                'actor_id'   => null,
                'action'     => DocumentAuditAction::DriveImported,
                'metadata'   => [
                    'drive_ingestion_id' => $ingestion->id,
                    'drive_file_id'      => $ingestion->drive_file_id,
                    'source'             => 'drive_import',
                ],
            ]);

            $ingestion->update([
                'document_id'  => $document->id,
                'status'       => DriveIngestionStatus::Registered,
                'processed_at' => now(),
            ]);

            // Phase 2 (classification + review queue + approval routing)
            // picks up from here -- dispatched, not called inline, to
            // match this plugin's async pattern (DiscoverDriveIngestionsJob)
            // and so a slow/failing classification never blocks
            // registration itself.
            ClassifyDriveIngestionJob::dispatch($ingestion->id);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Find-or-create $company's single inbound folder -- exactly the same
     * per-segment find-or-create walk DriveSyncService::resolveLeafFolder()
     * uses for export, just against resolveInboundFolder()'s fixed
     * 3-segment path instead of a per-document template.
     */
    public function resolveInboundFolder(Company $company): string
    {
        $segments = $this->pathResolver->resolveInboundFolder($company);
        $parentId = config('accounting_drive.shared_drive_id');

        foreach ($segments as $name) {
            $parentId = $this->drive->findFolder($name, $parentId)
                ?? $this->drive->createFolder($name, $parentId);
        }

        return $parentId;
    }

    private function parseModifiedTime(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
