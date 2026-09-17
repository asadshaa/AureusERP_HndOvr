<?php

namespace Webkul\Accounting\Contracts;

/**
 * The one boundary DocumentService is allowed to know about for physically
 * reading/writing a document's bytes. Business logic (checksum, company
 * isolation, versioning, audit) never touches Storage::disk(...) or any
 * cloud SDK directly -- it only ever talks to this interface, so swapping
 * the concrete provider (local today, S3 in a later phase, conceivably
 * Google Drive further still) never requires touching DocumentService.
 */
interface DocumentStorageProvider
{
    /**
     * Write $contents to $path. Returns false (or throws, depending on the
     * underlying disk's "throw" setting) on failure -- callers must check
     * the return value rather than assume success.
     */
    public function put(string $path, string $contents): bool;

    /**
     * Read the full contents of $path. Throws if the object does not exist
     * or cannot be read.
     */
    public function get(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /**
     * Size in bytes as actually stored, independent of whatever size the
     * caller believes it uploaded -- used to detect a write that silently
     * truncated or corrupted the file.
     */
    public function size(string $path): int;

    /**
     * Every object path currently in storage under $directory (recursive),
     * or the whole disk if omitted. Used only by the integrity checker to
     * find orphaned objects -- files sitting in storage with no
     * DocumentVersion row pointing at them -- never by any user-facing
     * document action.
     *
     * @return array<int, string>
     */
    public function allFiles(?string $directory = null): array;
}
