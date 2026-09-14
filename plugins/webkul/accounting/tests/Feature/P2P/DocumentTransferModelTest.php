<?php

use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Security\Models\UserDevice;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

function transferFixture(array $overrides = []): DocumentTransfer
{
    $sender = documentTestUser();
    $recipient = documentTestUser();

    $document = Document::factory()->create(['company_id' => $sender->default_company_id]);

    $device = UserDevice::query()->create([
        'user_id'                => $recipient->id,
        'company_id'             => $recipient->default_company_id,
        'label'                  => 'Recipient device',
        'public_key'             => 'KEY-'.bin2hex(random_bytes(4)),
        'public_key_fingerprint' => hash('sha256', bin2hex(random_bytes(8))),
    ]);

    return DocumentTransfer::query()->create(array_merge([
        'company_id'          => $sender->default_company_id,
        'document_id'         => $document->id,
        'sender_id'           => $sender->id,
        'recipient_id'        => $recipient->id,
        'recipient_device_id' => $device->id,
        'status'              => DocumentTransferStatus::Pending,
        'expires_at'          => now()->addDays(7),
    ], $overrides));
}

it('creates a pending transfer with its relations resolvable', function () {
    $transfer = transferFixture();

    expect($transfer->status)->toBe(DocumentTransferStatus::Pending)
        ->and($transfer->isPending())->toBeTrue()
        ->and($transfer->hasExpired())->toBeFalse()
        ->and($transfer->document)->not->toBeNull()
        ->and($transfer->sender)->not->toBeNull()
        ->and($transfer->recipient)->not->toBeNull()
        ->and($transfer->recipientDevice)->not->toBeNull();
});

it('reports a past expiry as expired', function () {
    $transfer = transferFixture(['expires_at' => now()->subMinute()]);

    expect($transfer->hasExpired())->toBeTrue();
});

it('scopes pending transfers to the recipient', function () {
    $mine = transferFixture();
    transferFixture();

    $ids = DocumentTransfer::query()
        ->pending()
        ->forRecipient($mine->recipient_id)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$mine->id]);
});

it('excludes delivered transfers from the pending scope', function () {
    $transfer = transferFixture(['status' => DocumentTransferStatus::Delivered]);

    expect(DocumentTransfer::query()->pending()->forRecipient($transfer->recipient_id)->count())->toBe(0);
});
