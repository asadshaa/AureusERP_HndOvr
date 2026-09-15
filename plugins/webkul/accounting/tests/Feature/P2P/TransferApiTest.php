<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

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
});

it('registers a device and lists it', function () {
    $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/devices', [
            'label'      => 'Windows desktop',
            'public_key' => 'SPKI-BYTES',
            'platform'   => 'windows',
        ])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Windows desktop');

    $this->actingAs($this->sender, 'sanctum')
        ->getJson('admin/api/v1/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects an unauthenticated device registration', function () {
    $this->postJson('admin/api/v1/devices', ['label' => 'X', 'public_key' => 'Y'])
        ->assertUnauthorized();
});

it('sends a transfer and shows it in the recipient inbox', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => hash('sha256', 'KEY-1'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->actingAs($this->recipient, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->sender, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lets the sender cancel a pending transfer over the API', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-3',
        'public_key_fingerprint' => hash('sha256', 'KEY-3'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $created = $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->sender, 'sanctum')
        ->postJson("admin/api/v1/document-transfers/{$created}/cancel")
        ->assertOk();

    $this->actingAs($this->recipient, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 422 when sending without the transfer permission', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => hash('sha256', 'KEY-2'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->actingAs($this->recipient, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertStatus(422);
});
