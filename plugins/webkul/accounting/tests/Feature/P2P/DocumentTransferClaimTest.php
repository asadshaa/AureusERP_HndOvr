<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->transfers = app(DocumentTransferService::class);
    $this->documents = app(DocumentService::class);

    $this->company = Company::factory()->create(['is_active' => true]);

    $this->sender = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);

    $this->recipient = documentTestUser($this->company, [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);

    $this->device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => hash('sha256', 'KEY-1'),
    ]);

    $this->document = $this->documents->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->transfer = $this->transfers->send($this->sender, $this->document->id, $this->device);
});

it('lists a pending transfer for its recipient only', function () {
    expect($this->transfers->pendingFor($this->recipient)->pluck('id')->all())->toBe([$this->transfer->id])
        ->and($this->transfers->pendingFor($this->sender)->count())->toBe(0);
});

it('delivers the bytes, marks the transfer delivered, and audits it', function () {
    $result = $this->transfers->claim($this->recipient, $this->transfer, '10.1.2.3');

    expect($result['contents'])->toBeString()
        ->and(hash('sha256', $result['contents']))->toBe($this->transfer->expected_sha256)
        ->and($result['version']->original_filename)->toBe('freight.pdf')
        ->and($result['transfer']->status)->toBe(DocumentTransferStatus::Delivered)
        ->and($result['transfer']->delivered_at)->not->toBeNull();

    $audit = $this->document->audits()->where('action', DocumentAuditAction::TransferDelivered)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($this->recipient->id)
        ->and($audit->metadata['transfer_id'])->toBe($this->transfer->id)
        ->and($audit->metadata['path'])->toBe('server');
});

it('refuses a claim by anyone other than the recipient', function () {
    expect(fn () => $this->transfers->claim($this->sender, $this->transfer))
        ->toThrow(RuntimeException::class, 'This transfer was not sent to you.');

    expect($this->transfer->fresh()->status)->toBe(DocumentTransferStatus::Pending);
});

it('refuses a second claim of the same transfer', function () {
    $this->transfers->claim($this->recipient, $this->transfer);

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer is no longer pending.');
});

it('refuses a claim after expiry', function () {
    $this->transfer->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer has expired.');
});

it('lets the sender cancel a pending transfer and audits it', function () {
    $cancelled = $this->transfers->cancel($this->sender, $this->transfer);

    expect($cancelled->status)->toBe(DocumentTransferStatus::Cancelled);

    expect($this->document->audits()->where('action', DocumentAuditAction::TransferCancelled)->exists())->toBeTrue();

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer is no longer pending.');
});

it('refuses cancellation by an unrelated user', function () {
    $outsider = documentTestUser($this->company);

    expect(fn () => $this->transfers->cancel($outsider, $this->transfer))
        ->toThrow(RuntimeException::class, 'You cannot cancel this transfer.');
});
