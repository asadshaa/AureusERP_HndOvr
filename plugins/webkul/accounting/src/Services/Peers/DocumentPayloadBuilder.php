<?php

namespace Webkul\Accounting\Services\Peers;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;

/**
 * Encodes a Document (and its bytes) for transmission, and decodes one that
 * arrives.
 *
 * Bytes travel inline as base64 rather than as a second authenticated fetch:
 * it keeps delivery a single atomic request, so there is no window where a
 * peer holds metadata for a file it cannot yet retrieve. The cost is ~33%
 * inflation, which is why the size ceiling is checked against the ENCODED
 * length, not the raw one.
 *
 * Decoding never trusts the sender: the declared sha256 is recomputed from
 * the received bytes, and the MIME type is re-validated against
 * DocumentService's own allowlist rather than taken from the payload.
 */
class DocumentPayloadBuilder
{
    public const FORMAT = 'aureus.document.v1';

    public function __construct(
        private readonly DocumentService $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Document $document): array
    {
        $read = $this->documents->readCurrentVersionForSync($document);
        $version = $read['version'];
        $bytes = $read['contents'];

        $encoded = base64_encode($bytes);

        $max = (int) config('accounting_peers.max_payload_bytes', 32 * 1024 * 1024);

        if (strlen($encoded) > $max) {
            throw new RuntimeException(
                "\"{$document->title}\" is too large to transmit once encoded ("
                .round(strlen($encoded) / 1048576, 1).' MB).'
            );
        }

        return [
            'format'    => self::FORMAT,
            'issued_at' => now()->toIso8601String(),
            'document'  => [
                'title'         => $document->title,
                'description'   => $document->description,
                'document_type' => $document->document_type?->value,
                'filename'      => $version->original_filename,
                'mime_type'     => $version->mime_type,
                'size_bytes'    => strlen($bytes),
                // Recomputed by the receiver before anything is stored.
                'sha256'        => hash('sha256', $bytes),
                'contents_b64'  => $encoded,
            ],
        ];
    }

    /**
     * Validate a received payload and materialise its bytes as an
     * UploadedFile, so the receiving side can push it through the ordinary
     * DocumentService::upload() path rather than writing storage directly.
     *
     * @param  array<string, mixed>  $payload
     * @return array{file: UploadedFile, title: string, description: ?string, document_type: ?string}
     */
    public function extract(array $payload): array
    {
        $doc = $payload['document'] ?? null;

        if (! is_array($doc) || empty($doc['contents_b64'])) {
            throw new RuntimeException('This transmission carries no file.');
        }

        $bytes = base64_decode((string) $doc['contents_b64'], true);

        if ($bytes === false) {
            throw new RuntimeException('The attached file is not valid base64.');
        }

        // Integrity before anything else touches it: a mismatch means the
        // file was corrupted or altered, and it must not be stored.
        $declared = (string) ($doc['sha256'] ?? '');
        $actual = hash('sha256', $bytes);

        if ($declared === '' || ! hash_equals($declared, $actual)) {
            throw new RuntimeException('The attached file failed its checksum check.');
        }

        $filename = $this->safeFilename((string) ($doc['filename'] ?? 'document'));

        // Written to a temp path so it can be handed to DocumentService as a
        // normal upload -- which re-runs MIME and size validation itself.
        $tmp = tempnam(sys_get_temp_dir(), 'aureus-peer-');

        if ($tmp === false) {
            throw new RuntimeException('Could not buffer the attached file.');
        }

        file_put_contents($tmp, $bytes);

        return [
            'file' => new UploadedFile(
                $tmp,
                $filename,
                $doc['mime_type'] ?? null,
                null,
                // Test mode: the file was written by us, not by a real HTTP
                // upload, so PHP's is_uploaded_file() check must be skipped.
                true,
            ),
            'title'         => (string) ($doc['title'] ?? $filename),
            'description'   => $doc['description'] ?? null,
            'document_type' => $doc['document_type'] ?? null,
        ];
    }

    /**
     * Strips any path component a sender may have included. A filename is
     * attacker-controlled input, and this one is about to hit the filesystem.
     */
    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'document';

        return substr(ltrim($name, '.'), 0, 180) ?: 'document';
    }
}
