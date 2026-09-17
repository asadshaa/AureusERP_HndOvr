<?php

use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentTransfer;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

function transferWithExpiry($expiresAt, DocumentTransferStatus $status = DocumentTransferStatus::Pending): DocumentTransfer
{
    $sender = documentTestUser();
    $recipient = documentTestUser();

    return DocumentTransfer::query()->create([
        'company_id'   => $sender->default_company_id,
        'document_id'  => Document::factory()->create(['company_id' => $sender->default_company_id])->id,
        'sender_id'    => $sender->id,
        'recipient_id' => $recipient->id,
        'status'       => $status,
        'expires_at'   => $expiresAt,
    ]);
}

it('marks pending transfers past their expiry as expired', function () {
    $stale = transferWithExpiry(now()->subDay());
    $live = transferWithExpiry(now()->addDay());

    $this->artisan('accounting:transfers:expire')
        ->expectsOutputToContain('Expired 1 transfer')
        ->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(DocumentTransferStatus::Expired)
        ->and($live->fresh()->status)->toBe(DocumentTransferStatus::Pending);
});

it('leaves already-delivered transfers untouched', function () {
    $delivered = transferWithExpiry(now()->subDay(), DocumentTransferStatus::Delivered);

    $this->artisan('accounting:transfers:expire')->assertExitCode(0);

    expect($delivered->fresh()->status)->toBe(DocumentTransferStatus::Delivered);
});
