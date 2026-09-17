<?php

namespace Webkul\Accounting\Tests\Helpers;

use Webkul\Accounting\Contracts\DriveClient;

/**
 * An in-memory stand-in for Google Drive -- the exact same role
 * Storage::fake('accounting_documents') plays for local/S3 storage.
 * Every DriveSyncService test binds this instead of GoogleDriveClient
 * (see driveTestBind() below), so none of them need real Google
 * credentials, a network call, or google/apiclient to even be loaded.
 *
 * Folder/file identity is just incrementing string ids ("folder-1",
 * "file-1", ...); "parents" is modeled as a flat map of id => parentId
 * (null = root), which is enough to prove findFolder()/createFolder()
 * stay idempotent per parent+name without needing a real tree structure.
 */
class FakeDriveClient implements DriveClient
{
    /** @var array<string, array{name: string, parent: ?string}> */
    public array $folders = [];

    /** @var array<string, array{name: string, mimeType: string, contents: string, parent: string, revision: int, trashed: bool}> */
    public array $files = [];

    private int $nextId = 1;

    /** Calls made to updateFileContent()/createFile(), in order -- lets a test assert export ran exactly once. */
    public array $writeLog = [];

    public function findFolder(string $name, ?string $parentFolderId): ?string
    {
        foreach ($this->folders as $id => $folder) {
            if ($folder['name'] === $name && $folder['parent'] === $parentFolderId) {
                return $id;
            }
        }

        return null;
    }

    public function findFile(string $name, string $parentFolderId): ?string
    {
        foreach ($this->files as $id => $file) {
            if ($file['name'] === $name && $file['parent'] === $parentFolderId && ! $file['trashed']) {
                return $id;
            }
        }

        return null;
    }

    public function createFolder(string $name, ?string $parentFolderId): string
    {
        $id = 'folder-'.$this->nextId++;
        $this->folders[$id] = ['name' => $name, 'parent' => $parentFolderId];

        return $id;
    }

    public function createFile(string $name, string $mimeType, string $contents, string $parentFolderId): array
    {
        $id = 'file-'.$this->nextId++;
        $this->files[$id] = [
            'name'     => $name,
            'mimeType' => $mimeType,
            'contents' => $contents,
            'parent'   => $parentFolderId,
            'revision' => 1,
            'trashed'  => false,
        ];
        $this->writeLog[] = ['op' => 'create', 'file_id' => $id];

        // The record above stays in $this->files even though we throw --
        // this simulates Drive having genuinely created the file
        // server-side while the RESPONSE carrying its id was lost
        // (timeout/network blip), which is exactly the scenario
        // findFile()'s recovery path in DriveSyncService exists for.
        if ($this->failNextWrites) {
            throw array_shift($this->failNextWrites);
        }

        return [$id, 'rev-1'];
    }

    public function updateFileContent(string $fileId, string $contents): ?string
    {
        if (! isset($this->files[$fileId])) {
            throw new \RuntimeException("FakeDriveClient: file {$fileId} does not exist.");
        }

        if ($this->failNextWrites) {
            throw array_shift($this->failNextWrites);
        }

        $this->files[$fileId]['contents'] = $contents;
        $this->files[$fileId]['revision']++;
        $this->writeLog[] = ['op' => 'update', 'file_id' => $fileId];

        return 'rev-'.$this->files[$fileId]['revision'];
    }

    public function fileExists(string $fileId): bool
    {
        return isset($this->files[$fileId]) && ! $this->files[$fileId]['trashed'];
    }

    public function webViewLink(string $fileId): string
    {
        return "https://drive.google.com/file/d/{$fileId}/view";
    }

    /** Test helper: simulate the Drive file having been trashed/deleted. */
    public function trash(string $fileId): void
    {
        $this->files[$fileId]['trashed'] = true;
    }

    /**
     * Test helper: queued exceptions consumed one-at-a-time by the next
     * createFile()/updateFileContent() call(s) -- for retry and
     * lost-response tests. Actually wired into both methods above,
     * unlike an earlier version of this helper that declared this but
     * never consulted it.
     */
    public array $failNextWrites = [];

    public function failNextWriteWith(\Throwable $e): void
    {
        $this->failNextWrites[] = $e;
    }
}
