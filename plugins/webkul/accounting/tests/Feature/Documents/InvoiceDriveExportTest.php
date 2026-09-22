<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Services\Drive\InvoiceDriveExportService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../../Helpers/FakeDriveClient.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    Storage::fake('accounting_documents');
    Storage::fake('public');

    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);

    $this->company = Company::factory()->create(['is_active' => true]);
    $this->user = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);

    $currency = Currency::query()->firstOrFail();
    $income = Account::factory()->create(['account_type' => AccountType::INCOME, 'currency_id' => $currency->id]);
    $journal = Journal::factory()->sale()->create([
        'company_id'         => $this->company->id,
        'currency_id'        => $currency->id,
        'default_account_id' => $income->id,
    ]);
    $partner = Partner::factory()->create([
        'property_account_receivable_id' => Account::factory()->create(['account_type' => AccountType::ASSET_RECEIVABLE, 'currency_id' => $currency->id])->id,
        'property_account_payable_id'    => Account::factory()->create(['account_type' => AccountType::LIABILITY_PAYABLE, 'currency_id' => $currency->id])->id,
    ]);

    $this->invoice = Move::factory()->create([
        'name'        => 'INV/2026/0099',
        'move_type'   => MoveType::OUT_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => $currency->id,
        'journal_id'  => $journal->id,
        'partner_id'  => $partner->id,
    ]);
    MoveLine::factory()->create([
        'move_id'      => $this->invoice->id,
        'display_type' => DisplayType::PRODUCT,
        'account_id'   => $income->id,
        'company_id'   => $this->company->id,
        'currency_id'  => $currency->id,
        'quantity'     => 1,
        'price_unit'   => 1500,
    ]);
    AccountFacade::computeAccountMove($this->invoice->refresh());

    $this->exportService = app(InvoiceDriveExportService::class);
});

it('exports an invoice to Google Drive creating a Document and DriveSync record', function () {
    $sync = $this->exportService->exportInvoice($this->user, $this->invoice);

    expect($sync->status)->toBe(DriveSyncStatus::Synced)
        ->and($sync->exists_in_drive)->toBeTrue()
        ->and($sync->drive_file_id)->not->toBeNull();

    // Verify Document attachment
    $attachment = $this->invoice->documentAttachments()->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->document->document_type)->toBe(DocumentType::Invoice);
    expect($attachment->document->company_id)->toBe($this->company->id);

    // Verify Drive folders and file creation
    expect($this->fakeDrive->files)->toHaveCount(1);
    $uploadedFile = array_values($this->fakeDrive->files)[0];
    $expectedFileName = Str::slug(str_replace(['/', '\\'], '-', $this->invoice->name)).'.pdf';
    expect($uploadedFile['name'])->toBe($expectedFileName);
});

it('uses an existing rendered PDF if provided from public disk', function () {
    $customPdfContent = '%PDF-1.4 custom test pdf content';
    $diskPath = 'pdfs/invoice-test.pdf';
    Storage::disk('public')->put($diskPath, $customPdfContent);

    $sync = $this->exportService->exportInvoice($this->user, $this->invoice, $diskPath);

    expect($sync->status)->toBe(DriveSyncStatus::Synced);
    expect($this->fakeDrive->files)->toHaveCount(1);
});

it('creates a new version if the invoice is re-exported to Drive', function () {
    $sync1 = $this->exportService->exportInvoice($this->user, $this->invoice);
    $doc = $this->invoice->documentAttachments()->first()->document;
    expect($doc->versions)->toHaveCount(1);

    $sync2 = $this->exportService->exportInvoice($this->user, $this->invoice);
    $doc->refresh();
    expect($doc->versions)->toHaveCount(2);
});

it('throws an exception when Google Drive sync is disabled', function () {
    Config::set('accounting_drive.enabled', false);

    expect(fn () => $this->exportService->exportInvoice($this->user, $this->invoice))
        ->toThrow(RuntimeException::class, 'Google Drive sync is not enabled');
});
