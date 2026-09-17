<?php

/**
 * Confirmed by adversarial audit: every received file left a full plaintext
 * copy in %TEMP% forever. DocumentPayloadBuilder::extract() writes decoded
 * bytes to a temp file for DocumentService to consume, but nothing deleted
 * it afterwards.
 *
 * Rather than sandboxing sys_get_temp_dir() (its result did not reliably
 * follow putenv() mid-process on this runtime -- confirmed by direct probe,
 * a real platform quirk and not worth fighting), this diffs the REAL system
 * temp directory by the tool's own file-name prefix before and after the
 * call. Anything matching 'aureus-peer-*' afterwards is the leak this test
 * exists to catch.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\DocumentPayloadBuilder;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

function aureusPeerTempFiles(): array
{
    return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'aureus-peer-*') ?: [];
}

/** Real magic bytes: MIME sniffing is by content, not by claimed type or extension. */
function realPdfBytesForCleanupTest(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->company = Company::factory()->create(['is_active' => true]);
    $this->sender = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);

    // Random per test, not a fixed literal: inbound_token_hash is unique,
    // and this file's two tests colliding on it produced a real (if
    // unrelated) integrity-violation failure when the database was not
    // rolled back between them.
    $this->peer = Peer::create([
        'company_id'         => $this->company->id,
        'name'               => 'Some Peer',
        'endpoint_url'       => 'https://peer.example.test',
        'status'             => PeerStatus::Active,
        'signing_secret'     => bin2hex(random_bytes(32)),
        'inbound_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        'paired_at'          => now(),
    ]);

    // Baseline BEFORE this test's own operations, so a stray file from an
    // unrelated process never causes a false failure -- only files this
    // test's own call produced count.
    $this->before = aureusPeerTempFiles();
});

afterEach(function () {
    // Best-effort: an assertion failure stops before any leaked file would
    // otherwise get cleaned up by the fix itself.
    foreach (array_diff(aureusPeerTempFiles(), $this->before) as $leaked) {
        @unlink($leaked);
    }
});

it('leaves no temp file behind after successfully receiving a file', function () {
    $document = app(DocumentService::class)->upload(
        $this->sender, $this->company->id, DocumentType::Receipt,
        'Cleanup test', null,
        UploadedFile::fake()->createWithContent('cleanup.pdf', realPdfBytesForCleanupTest()),
    );

    $payload = app(DocumentPayloadBuilder::class)->build($document);

    app(DocumentExchangeService::class)->receive($this->peer, 'cleanup-ref-1', $payload);

    expect(array_diff(aureusPeerTempFiles(), $this->before))->toBe([]);
});

it('leaves no temp file behind when the received file exceeds the size ceiling', function () {
    $document = app(DocumentService::class)->upload(
        $this->sender, $this->company->id, DocumentType::Receipt,
        'Oversized on receipt', null,
        UploadedFile::fake()->createWithContent('cleanup2.pdf', realPdfBytesForCleanupTest()),
    );

    $payload = app(DocumentPayloadBuilder::class)->build($document);

    // Genuinely exceeds MAX_FILE_SIZE_BYTES (20 MB) once decoded, so
    // DocumentService::validateFile() rejects it AFTER extract() has
    // already written the temp file -- exactly the failure-path leak the
    // audit found (the finally block must run here too, not only on
    // success).
    $oversized = str_repeat('A', 21 * 1024 * 1024);
    $payload['document']['contents_b64'] = base64_encode($oversized);
    $payload['document']['sha256'] = hash('sha256', $oversized);
    $payload['document']['size_bytes'] = strlen($oversized);

    // Arrow function, not a plain closure: a plain function(){} does not
    // auto-capture $payload from the enclosing scope (only $this does),
    // which produced "Undefined variable $payload" here on the first pass.
    expect(fn () => app(DocumentExchangeService::class)->receive($this->peer, 'cleanup-ref-2', $payload))
        ->toThrow(RuntimeException::class, '20MB limit');

    expect(array_diff(aureusPeerTempFiles(), $this->before))->toBe([]);
});
