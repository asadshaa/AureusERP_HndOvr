<?php

/**
 * Proves a FILE survives the trip byte-for-byte.
 *
 * The invoice round-trip test covers structured data; this covers the other
 * half of "invoices and documents" -- real bytes, encoded, signed, decoded,
 * checksum-verified, and stored through the ordinary DocumentService path.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\InboundTransmissionStatus;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Models\InboundTransmission;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\DocumentPayloadBuilder;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\Peers\PeerSignature;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->senderCompany = Company::factory()->create(['is_active' => true]);
    $this->receiverCompany = Company::factory()->create(['is_active' => true]);

    $this->sender = documentTestUser($this->senderCompany, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::SendTransmissions,
        AccountingPermissions::DownloadDocuments,
    ]);

    $this->reviewer = documentTestUser($this->receiverCompany, [
        AccountingPermissions::ReviewInboundTransmissions,
        AccountingPermissions::DownloadDocuments,
    ]);

    $this->secret = 'file-test-secret-'.bin2hex(random_bytes(8));
    $this->token = 'file-test-token-'.bin2hex(random_bytes(8));

    $this->peerOnReceiver = Peer::create([
        'company_id'         => $this->receiverCompany->id,
        'name'               => 'Sending Instance',
        'endpoint_url'       => 'https://sender.example.test',
        'status'             => PeerStatus::Active,
        'signing_secret'     => $this->secret,
        'inbound_token_hash' => hash('sha256', $this->token),
        'paired_at'          => now(),
    ]);
});

/** Real bytes, not a stub: the checksum assertions below mean nothing otherwise. */
function realPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
        .str_repeat('AureusERP peer file transfer payload. ', 200);
}

function postSignedFile(object $ctx, string $body): TestResponse
{
    $headers = PeerSignature::headers($body, $ctx->secret);

    return $ctx->call(
        'POST',
        '/api/v1/peer/transmissions',
        [], [], [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all()
            + ['HTTP_AUTHORIZATION' => 'Bearer '.$ctx->token, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

it('carries a file from one instance to the other with its bytes intact', function () {
    $bytes = realPdfBytes();

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->senderCompany->id,
        DocumentType::Receipt,
        'Supplier receipt',
        'Scanned at the counter',
        UploadedFile::fake()->createWithContent('receipt.pdf', $bytes),
    );

    $payload = app(DocumentPayloadBuilder::class)->build($document);

    expect($payload['format'])->toBe(DocumentPayloadBuilder::FORMAT)
        ->and($payload['document']['sha256'])->toBe(hash('sha256', $bytes))
        ->and(base64_decode($payload['document']['contents_b64']))->toBe($bytes);

    $body = json_encode(['reference' => 'file-round-trip-1', 'payload' => $payload], JSON_UNESCAPED_SLASHES);

    postSignedFile($this, $body)->assertStatus(202);

    $inbound = InboundTransmission::query()->firstOrFail();

    expect($inbound->payload_type)->toBe(DocumentPayloadBuilder::FORMAT)
        ->and($inbound->document_id)->not->toBeNull()
        ->and($inbound->status)->toBe(InboundTransmissionStatus::Received);

    // The bytes the receiver now holds must be the bytes the sender sent.
    $stored = app(DocumentService::class)->retrieveContents($this->reviewer, $inbound->document_id);

    expect($stored['contents'])->toBe($bytes)
        ->and($stored['version']->original_filename)->toBe('receipt.pdf')
        ->and($stored['version']->checksum_sha256)->toBe(hash('sha256', $bytes));

    // Received is not accepted: the file exists, the human decision has not
    // happened yet, and accepting a file creates no bill.
    $result = app(DocumentExchangeService::class)->accept($this->reviewer, $inbound);

    expect($result)->toBeNull()
        ->and($inbound->fresh()->status)->toBe(InboundTransmissionStatus::Accepted);
});

it('refuses a file whose checksum does not match its bytes', function () {
    $document = app(DocumentService::class)->upload(
        $this->sender, $this->senderCompany->id, DocumentType::Receipt,
        'Tampered receipt', null,
        UploadedFile::fake()->createWithContent('t.pdf', realPdfBytes()),
    );

    $payload = app(DocumentPayloadBuilder::class)->build($document);

    // Bytes altered in transit while the declared hash stays the same.
    $payload['document']['contents_b64'] = base64_encode('totally different content');

    $body = json_encode(['reference' => 'bad-checksum', 'payload' => $payload], JSON_UNESCAPED_SLASHES);

    postSignedFile($this, $body)->assertStatus(422);

    expect(InboundTransmission::query()->count())->toBe(0);
});

it('strips any directory path a sender puts in the filename', function () {
    $extracted = app(DocumentPayloadBuilder::class)->extract([
        'format'   => DocumentPayloadBuilder::FORMAT,
        'document' => [
            'title'        => 'Traversal attempt',
            'filename'     => '../../../../windows/system32/evil.pdf',
            'mime_type'    => 'application/pdf',
            'sha256'       => hash('sha256', 'x'),
            'contents_b64' => base64_encode('x'),
        ],
    ]);

    expect($extracted['file']->getClientOriginalName())
        ->not->toContain('..')
        ->not->toContain('/')
        ->toBe('evil.pdf');
});

it('rejects a file that exceeds the payload ceiling rather than queueing it', function () {
    config()->set('accounting_peers.max_payload_bytes', 1024);

    $document = app(DocumentService::class)->upload(
        $this->sender, $this->senderCompany->id, DocumentType::Receipt,
        'Large receipt', null,
        UploadedFile::fake()->createWithContent('big.pdf', str_repeat('A', 4096)),
    );

    expect(fn () => app(DocumentPayloadBuilder::class)->build($document))
        ->toThrow(RuntimeException::class, 'too large to transmit');
});
