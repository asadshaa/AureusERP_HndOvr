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
});

function deviceFor($user, string $label = 'Device'): UserDevice
{
    return UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => $label,
        'public_key'             => 'KEY-'.bin2hex(random_bytes(4)),
        'public_key_fingerprint' => hash('sha256', bin2hex(random_bytes(8))),
    ]);
}

function uploadedDocumentFor($service, $user)
{
    return $service->upload(
        $user,
        $user->default_company_id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );
}

it('creates a pending transfer, pins the checksum, and audits the send', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);
    $device = deviceFor($recipient);

    $document = uploadedDocumentFor($this->documents, $sender);

    $transfer = $this->transfers->send($sender, $document->id, $device, 'For your review', '10.0.0.9');

    expect($transfer->status)->toBe(DocumentTransferStatus::Pending)
        ->and($transfer->company_id)->toBe($company->id)
        ->and($transfer->sender_id)->toBe($sender->id)
        ->and($transfer->recipient_id)->toBe($recipient->id)
        ->and($transfer->recipient_device_id)->toBe($device->id)
        ->and($transfer->note)->toBe('For your review')
        ->and($transfer->expected_sha256)->toBe($document->currentVersion->checksum_sha256)
        ->and($transfer->expires_at)->not->toBeNull();

    $audit = $document->audits()->where('action', DocumentAuditAction::TransferSent)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($sender->id)
        ->and($audit->ip_address)->toBe('10.0.0.9')
        ->and($audit->metadata['recipient_id'])->toBe($recipient->id)
        ->and($audit->metadata['device_id'])->toBe($device->id);
});

it('refuses to send without the transfer permission', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, deviceFor($recipient)))
        ->toThrow(RuntimeException::class);
});

it('refuses to send to a device in another company', function () {
    $sender = documentTestUser(null, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $outsider = documentTestUser();

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, deviceFor($outsider)))
        ->toThrow(RuntimeException::class, 'That device belongs to a different company.');
});

it('refuses to send to a revoked device', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);

    $device = deviceFor($recipient);
    $device->update(['revoked_at' => now()]);

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, $device))
        ->toThrow(RuntimeException::class, 'That device has been revoked.');
});

it('refuses to send once the pending cap is reached', function () {
    config()->set('accounting_transfer.max_pending_per_sender', 1);

    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);
    $device = deviceFor($recipient);

    $this->transfers->send($sender, uploadedDocumentFor($this->documents, $sender)->id, $device);

    expect(fn () => $this->transfers->send($sender, uploadedDocumentFor($this->documents, $sender)->id, $device))
        ->toThrow(RuntimeException::class, 'You have too many pending transfers.');
});
