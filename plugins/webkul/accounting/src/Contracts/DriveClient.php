<?php

namespace Webkul\Accounting\Contracts;

/**
 * The one boundary DriveSyncService is allowed to know about for actually
 * talking to Google Drive -- mirrors DocumentStorageProvider's role for
 * local/S3 storage exactly. Nothing outside DriveSyncService (and its
 * queued jobs) is allowed to reference this interface or a Drive SDK
 * class directly; that keeps every other part of the document domain
 * completely unaware Drive exists, and keeps GoogleDriveClient swappable
 * for a fake in tests without any real Google credentials.
 */
interface DriveClient
{
    /**
     * Find a direct child folder of $parentFolderId by exact name.
     * Returns null if no such folder exists yet -- callers use this to
     * decide whether to create one, which is what keeps folder-path
     * resolution idempotent (never creates a duplicate "Invoices" folder
     * on a second run).
     */
    public function findFolder(string $name, ?string $parentFolderId): ?string;

    /**
     * Create a folder named $name under $parentFolderId (null means the
     * shared drive's root) and return its new Drive folder id.
     */
    public function createFolder(string $name, ?string $parentFolderId): string;

    /**
     * Find a direct child file of $parentFolderId by exact name. Used
     * to recover when a prior createFile() call succeeded on Drive's
     * side but its response was lost (timeout/network blip) before this
     * app recorded the new file's id -- without this, a retry would
     * call createFile() again and produce a second, orphaned file with
     * the same name.
     */
    public function findFile(string $name, string $parentFolderId): ?string;

    /**
     * Create a new file with $contents under $parentFolderId. Returns
     * [fileId, revisionId].
     *
     * @return array{0: string, 1: ?string}
     */
    public function createFile(string $name, string $mimeType, string $contents, string $parentFolderId): array;

    /**
     * Replace the CONTENT of an existing file in place -- this is what
     * makes export idempotent: a document that already has a
     * drive_file_id is always updated, never re-created. Returns the new
     * revisionId (or null if Drive doesn't expose one for this file).
     */
    public function updateFileContent(string $fileId, string $contents): ?string;

    /**
     * True if $fileId still exists (and isn't trashed) in Drive.
     */
    public function fileExists(string $fileId): bool;

    /**
     * A stable, human-shareable URL for opening $fileId directly in the
     * Drive web UI.
     */
    public function webViewLink(string $fileId): string;

    /**
     * List the direct child FILES (not folders) of $parentFolderId --
     * used by the Drive -> Aureus ingestion direction to discover what a
     * human has dropped into a company's inbound folder. Each entry
     * carries the identity fields DriveIngestion needs to capture at
     * discovery time.
     *
     * @return array<int, array{id: string, name: string, mimeType: string, size: int, modifiedTime: ?string}>
     */
    public function listFiles(string $parentFolderId): array;

    /**
     * Download the raw bytes of $fileId. The import-direction mirror of
     * createFile()/updateFileContent()'s writes -- used to verify a
     * discovered file's checksum and, once verified, to register it as a
     * new Aureus Document.
     */
    public function downloadFileContent(string $fileId): string;
}
