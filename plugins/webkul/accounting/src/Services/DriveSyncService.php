<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentDriveSync;
use Webkul\Accounting\Models\DocumentVersion;
use Webkul\Accounting\Support\DriveFolderPathResolver;

/**
 * Export-direction Drive sync only (Aureus -> Drive). The Drive->Aureus
 * import direction -- files appearing/changing/disappearing in Drive --
 * is a separate, later phase; nothing here reads FROM Drive except to
 * check a folder/file's existence before deciding whether to create it.
 *
 * This is the only class in the codebase allowed to hold a DriveClient
 * reference. It calls DocumentService for nothing in this phase (export
 * only reads a Document/its current version -- it never creates or
 * mutates document content), but any future addition that DOES need to
 * touch document content (the import phase's new-version-from-Drive
 * logic) must go through DocumentService, not write to
 * DocumentVersion/Document directly.
 */
class DriveSyncService
{
    public function __construct(
        private readonly DriveClient $drive,
        private readonly DriveFolderPathResolver $pathResolver,
    ) {}

    /**
     * Idempotent: safe to call twice for the same document, including
     * from two genuinely concurrent processes (two queue workers, or a
     * manual "Sync now" click racing the automatic post-upload job) --
     * every export() for the same company is serialized behind a lock,
     * which is what actually makes that guarantee hold rather than just
     * the sequential-call case.
     */
    public function export(Document $document): DocumentDriveSync
    {
        if (! config('accounting_drive.enabled')) {
            throw new RuntimeException('Google Drive sync is not enabled for this installation.');
        }

        // Refresh rather than trust $document->currentVersion as handed
        // to us -- a caller holding an older in-memory instance (e.g. a
        // future bulk re-sync command iterating documents) could
        // otherwise have this upload the WRONG version's bytes.
        $document->refresh();
        $version = $document->currentVersion;

        if (! $version) {
            throw new RuntimeException("Document \"{$document->title}\" has no uploaded file yet -- nothing to export.");
        }

        // Serializes every export() for one company -- this is what
        // actually closes two races that a find-then-create pattern
        // cannot close on its own: resolveLeafFolder()'s walk across
        // Drive folder segments (two processes could otherwise both find
        // "Invoices" missing and both create it), and the
        // DocumentDriveSync firstOrNew()+save() just below (two processes
        // could otherwise both see no row yet and both attempt an
        // INSERT). Scoped per company, not globally, so unrelated
        // companies' exports never wait on each other.
        $lock = Cache::lock("accounting-drive-sync:company:{$document->company_id}", 60);

        try {
            return $lock->block(15, fn () => $this->exportLocked($document, $version));
        } catch (Throwable $e) {
            // A lock-acquisition timeout lands here without ever having
            // touched $sync -- still leave a Failed row behind rather
            // than letting this escape completely unaudited. Harmless if
            // exportLocked() already recorded the same failure itself;
            // this just re-affirms the same end state.
            DocumentDriveSync::query()->updateOrCreate(
                ['document_id' => $document->id],
                ['status' => DriveSyncStatus::Failed, 'last_sync_error' => $e->getMessage()],
            );

            throw $e;
        }
    }

    private function exportLocked(Document $document, DocumentVersion $version): DocumentDriveSync
    {
        // A fresh query, not $document->driveSync -- that relation can be
        // cached stale (null) on this same $document instance from an
        // earlier call within the same request/test, which would try to
        // INSERT a second row here and hit the document_id unique
        // constraint instead of updating the one that already exists.
        $sync = DocumentDriveSync::query()->firstOrNew(['document_id' => $document->id]);
        $sync->status = DriveSyncStatus::Pending;
        $sync->save();

        try {
            $parentFolderId = $this->resolveLeafFolder($document);

            $contents = app(DocumentService::class)->readCurrentVersionForSync($document)['contents'];

            if ($sync->drive_file_id && $this->drive->fileExists($sync->drive_file_id)) {
                $revisionId = $this->drive->updateFileContent($sync->drive_file_id, $contents);
            } else {
                // We have no stored drive_file_id, but Drive may already
                // have this file if a PRIOR createFile() call actually
                // succeeded server-side and only its response was lost
                // (timeout/network blip) before we recorded the id --
                // reuse that file instead of creating a second one with
                // the same name in the same folder.
                $existingFileId = $this->drive->findFile($version->original_filename, $parentFolderId);

                if ($existingFileId) {
                    $revisionId = $this->drive->updateFileContent($existingFileId, $contents);
                    $sync->drive_file_id = $existingFileId;
                } else {
                    [$fileId, $revisionId] = $this->drive->createFile(
                        $version->original_filename,
                        $version->mime_type,
                        $contents,
                        $parentFolderId,
                    );
                    $sync->drive_file_id = $fileId;
                }
            }

            $sync->fill([
                'drive_parent_folder_id' => $parentFolderId,
                'drive_revision_id'      => $revisionId,
                'last_synced_version_id' => $version->id,
                'last_synced_checksum'   => $version->checksum_sha256,
                'last_synced_at'         => now(),
                'status'                 => DriveSyncStatus::Synced,
                'last_sync_error'        => null,
                'exists_in_drive'        => true,
            ]);
            $sync->save();

            $document->audits()->create([
                'company_id' => $document->company_id,
                'actor_id'   => null,
                'action'     => DocumentAuditAction::DriveExported,
                'metadata'   => [
                    'drive_file_id' => $sync->drive_file_id,
                    'version_id'    => $version->id,
                    'triggered_by'  => $document->creator_id,
                ],
            ]);

            return $sync->refresh();
        } catch (Throwable $e) {
            $sync->fill([
                'status'          => DriveSyncStatus::Failed,
                'last_sync_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Walk the resolved path, finding-or-creating each folder level in
     * turn. Every level is looked up by name before being created --
     * safe against repeated/retried calls for the same document, and
     * (because export() holds a per-company lock around this entire
     * method) safe against two documents in the same company racing on
     * a shared folder segment too.
     */
    private function resolveLeafFolder(Document $document): string
    {
        $segments = $this->pathResolver->resolve($document);
        $parentId = config('accounting_drive.shared_drive_id');

        foreach ($segments as $name) {
            $parentId = $this->drive->findFolder($name, $parentId)
                ?? $this->drive->createFolder($name, $parentId);
        }

        return $parentId;
    }
}
