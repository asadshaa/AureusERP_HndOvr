<?php

namespace Webkul\Accounting\Services\Drive;

use Google\Client;
use Google\Service\Drive as DriveService;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;
use RuntimeException;
use Webkul\Accounting\Contracts\DriveClient;

/**
 * The only class that actually talks to Google's SDK. Everything else in
 * the codebase (DriveSyncService included) only ever sees the DriveClient
 * interface -- swapping this for a different account, a Shared Drive, or
 * even a different provider entirely later never touches anything else.
 *
 * Authenticated with a stored OAuth refresh token (see
 * AuthorizeDriveCommand), not a service account -- this Google account is
 * a personal Drive, and a bare service account cannot own files there at
 * all (zero storage quota outside Workspace).
 */
class GoogleDriveClient implements DriveClient
{
    private DriveService $service;

    public function __construct()
    {
        $client = new Client;
        $client->setClientId(config('accounting_drive.client_id'));
        $client->setClientSecret(config('accounting_drive.client_secret'));
        $client->setScopes(config('accounting_drive.scopes', []));
        $token = $client->refreshToken(config('accounting_drive.refresh_token'));
        if (is_array($token) && isset($token['error'])) {
            throw new RuntimeException('Google Drive OAuth authentication failed: '.($token['error_description'] ?? $token['error']).". Please re-authorize via 'php artisan accounting:drive:authorize'.");
        }

        $this->service = new DriveService($client);
    }

    public function findFolder(string $name, ?string $parentFolderId): ?string
    {
        return $this->findByName($name, $parentFolderId, "mimeType='application/vnd.google-apps.folder' and ");
    }

    public function findFile(string $name, string $parentFolderId): ?string
    {
        return $this->findByName($name, $parentFolderId, '');
    }

    private function findByName(string $name, ?string $parentFolderId, string $extraClause): ?string
    {
        $parentClause = $parentFolderId ? "'{$parentFolderId}' in parents" : "'root' in parents";
        $escapedName = $this->escapeForDriveQuery($name);

        $result = $this->service->files->listFiles([
            'q'                         => "name='{$escapedName}' and {$parentClause} and {$extraClause}trashed=false",
            'spaces'                    => 'drive',
            'fields'                    => 'files(id)',
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
            'pageSize'                  => 1,
        ]);

        $files = $result->getFiles();

        // ?-> alone isn't enough here: with zero matches $files is an
        // empty array, and accessing offset 0 on it directly (rather
        // than a null value at that offset) is what actually triggers
        // PHP's "undefined array key" warning -- caught live during
        // manual verification against the real Drive API.
        return ($files[0] ?? null)?->getId();
    }

    /**
     * Google's documented escaping for the `q` filter: a literal
     * backslash must itself be escaped BEFORE escaping quotes, or a
     * name containing one would produce a malformed clause. Order
     * matters -- backslashes first, or escaping the quote would double
     * up.
     */
    private function escapeForDriveQuery(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    public function createFolder(string $name, ?string $parentFolderId): string
    {
        $metadata = new DriveFile([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => $parentFolderId ? [$parentFolderId] : null,
        ]);

        $folder = $this->service->files->create($metadata, [
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ]);

        return $folder->getId();
    }

    public function createFile(string $name, string $mimeType, string $contents, string $parentFolderId): array
    {
        $metadata = new DriveFile([
            'name'    => $name,
            'parents' => [$parentFolderId],
        ]);

        $file = $this->service->files->create($metadata, [
            'data'              => $contents,
            'mimeType'          => $mimeType,
            'uploadType'        => 'multipart',
            'fields'            => 'id, headRevisionId',
            'supportsAllDrives' => true,
        ]);

        return [$file->getId(), $file->getHeadRevisionId()];
    }

    public function updateFileContent(string $fileId, string $contents): ?string
    {
        $file = $this->service->files->update($fileId, new DriveFile, [
            'data'              => $contents,
            'uploadType'        => 'multipart',
            'fields'            => 'id, headRevisionId',
            'supportsAllDrives' => true,
        ]);

        return $file->getHeadRevisionId();
    }

    public function fileExists(string $fileId): bool
    {
        try {
            $file = $this->service->files->get($fileId, [
                'fields'            => 'id, trashed',
                'supportsAllDrives' => true,
            ]);

            return ! $file->getTrashed();
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw new RuntimeException('Could not check whether the Drive file still exists: '.$e->getMessage(), previous: $e);
        }
    }

    public function webViewLink(string $fileId): string
    {
        return "https://drive.google.com/file/d/{$fileId}/view";
    }

    public function listFiles(string $parentFolderId): array
    {
        $result = $this->service->files->listFiles([
            'q'                         => "'{$parentFolderId}' in parents and mimeType!='application/vnd.google-apps.folder' and trashed=false",
            'spaces'                    => 'drive',
            'fields'                    => 'files(id, name, mimeType, size, modifiedTime)',
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
            'pageSize'                  => 1000,
        ]);

        return array_map(fn (DriveFile $file) => [
            'id'           => $file->getId(),
            'name'         => $file->getName(),
            'mimeType'     => $file->getMimeType(),
            'size'         => (int) $file->getSize(),
            'modifiedTime' => $file->getModifiedTime(),
        ], $result->getFiles());
    }

    public function downloadFileContent(string $fileId): string
    {
        $response = $this->service->files->get($fileId, [
            'alt'               => 'media',
            'supportsAllDrives' => true,
        ]);

        return (string) $response->getBody();
    }

    public function trashFile(string $fileId): void
    {
        try {
            $this->service->files->update($fileId, new DriveFile(['trashed' => true]), [
                'supportsAllDrives' => true,
            ]);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return;
            }

            throw new RuntimeException('Could not trash the Drive file: '.$e->getMessage(), previous: $e);
        }
    }
}
