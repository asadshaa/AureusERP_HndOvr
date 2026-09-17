<?php

namespace Webkul\Accounting\Services;

use Webkul\Accounting\Contracts\DocumentStorageProvider;
use Webkul\Accounting\Models\DocumentVersion;

/**
 * Read-only detection, deliberately not remediation. Finds two kinds of
 * drift between the database and the storage disk:
 *
 *  - a "missing object": a DocumentVersion row whose file is no longer in
 *    storage (deleted, moved, or lost outside the application).
 *  - an "orphan file": a file sitting in storage that no DocumentVersion
 *    row points at (e.g. left behind by a write that crashed between
 *    storing the object and inserting its row).
 *
 * Neither is fixed automatically -- a human needs to look at each before
 * anything is restored or deleted, so this only ever reports.
 */
class DocumentIntegrityChecker
{
    public function __construct(
        private readonly DocumentStorageProvider $storage,
    ) {}

    /**
     * @return array{versions_checked: int, missing_objects: array<int, array{document_id: int, version_id: int, version_number: int, storage_path: string}>, orphan_files: array<int, string>}
     */
    public function check(?int $companyId = null): array
    {
        $versions = DocumentVersion::query()
            ->when(
                $companyId,
                fn ($query) => $query->whereHas('document', fn ($documentQuery) => $documentQuery->where('company_id', $companyId)),
            )
            ->get();

        $missing = [];

        foreach ($versions as $version) {
            if (! $this->storage->exists($version->storage_path)) {
                $missing[] = [
                    'document_id'    => $version->document_id,
                    'version_id'     => $version->id,
                    'version_number' => $version->version_number,
                    'storage_path'   => $version->storage_path,
                ];
            }
        }

        $directory = $companyId ? "companies/{$companyId}" : null;
        $knownPaths = $versions->pluck('storage_path')->all();
        $orphans = array_values(array_diff($this->storage->allFiles($directory), $knownPaths));

        return [
            'versions_checked' => $versions->count(),
            'missing_objects'  => $missing,
            'orphan_files'     => $orphans,
        ];
    }
}
