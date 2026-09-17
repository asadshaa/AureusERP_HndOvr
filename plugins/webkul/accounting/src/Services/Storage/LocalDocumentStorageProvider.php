<?php

namespace Webkul\Accounting\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Contracts\DocumentStorageProvider;

/**
 * Wraps the 'accounting_documents' disk registered in Phase 1
 * (config/filesystems.php). Despite the class name, this provider is not
 * actually tied to the local driver -- DOC_STORAGE_DISK already lets that
 * one disk resolve to either 'local' or 's3' -- so this class today covers
 * both "development on the local filesystem" and "production on S3"
 * without a separate S3DocumentStorageProvider existing yet. A dedicated
 * S3DocumentStorageProvider can still be introduced later (e.g. for
 * S3-specific behaviour like presigned URLs) without DocumentService
 * changing at all.
 */
class LocalDocumentStorageProvider implements DocumentStorageProvider
{
    private readonly Filesystem $disk;

    public function __construct(?Filesystem $disk = null)
    {
        $this->disk = $disk ?? Storage::disk('accounting_documents');
    }

    public function put(string $path, string $contents): bool
    {
        return (bool) $this->disk->put($path, $contents);
    }

    public function get(string $path): string
    {
        return $this->disk->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }

    public function size(string $path): int
    {
        return $this->disk->size($path);
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->disk->allFiles($directory);
    }
}
