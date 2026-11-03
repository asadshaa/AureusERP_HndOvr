<?php

use Filament\Facades\Filament;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveInvoicePostingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

it('renders ViewDriveIngestionClassification page with auto-detected values and posts to ledger', function () {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    Journal::factory()->create([
        'company_id' => $company->id,
        'type'       => JournalType::SALE,
    ]);

    $user = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $user->givePermissionTo(AccountingPermissions::ManageDocuments);
    $user->givePermissionTo(AccountingPermissions::ViewDocuments);
    // "Confirm & Post to Ledger" and the direct post are Post Journal acts.
    $user->givePermissionTo(AccountingPermissions::PostJournal);

    $glAccount = Account::factory()->create([
        'code'         => 'REV'.uniqid(),
        'name'         => 'Product Sales',
        'account_type' => AccountType::INCOME,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $glAccount->companies()->attach($company->id);

    $fsTag = FsTag::query()->create([
        'company_id' => $company->id,
        'account_id' => $glAccount->id,
        'code'       => 'FS-MANUAL-REV',
        'name'       => 'Operating Revenue',
        'is_active'  => true,
    ]);

    $receivable = Account::factory()->create([
        'code'         => 'AR'.uniqid(),
        'name'         => 'Accounts Receivable',
        'account_type' => AccountType::ASSET_RECEIVABLE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
        'reconcile'    => true,
    ]);
    $receivable->companies()->attach($company->id);

    $partner = Partner::factory()->create([
        'company_id'                      => $company->id,
        'name'                            => 'ApexLogistics',
        'customer_rank'                   => 1,
        'property_account_receivable_id'  => $receivable->id,
    ]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => 'file-'.uniqid(),
        'checksum_sha256' => hash('sha256', 'test_invoice_1.pdf'),
        'mime_type'       => 'application/pdf',
        'file_size'       => 1024,
        'filename'        => 'test_invoice_1.pdf',
        'status'          => DriveIngestionStatus::Registered,
        'discovered_at'   => now(),
        'processed_at'    => now(),
    ]);

    $classification = DriveIngestionClassification::query()->create([
        'company_id'               => $company->id,
        'drive_ingestion_id'       => $ingestion->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-TEST-001',
        'extracted_partner_name'   => 'Apex Global Logistics',
        'extracted_amount'         => 1500.00,
        'extracted_currency_code'  => 'PKR',
        'extracted_date'           => '2026-10-01',
        'extracted_fs_tag_code'    => 'FS-MANUAL-REV',
        'resolved_partner_id'      => $partner->id,
        'resolved_fs_tag_id'       => $fsTag->id,
        'resolved_account_id'      => $glAccount->id,
        'validation_status'        => DriveClassificationStatus::Valid,
    ]);

    test()->actingAs($user);

    $url = DriveIngestionClassificationResource::getUrl('view', ['record' => $classification->id]);
    $response = test()->get($url);

    $response->assertSuccessful();
    $response->assertSee('Customer Invoice');
    $response->assertSee('INV-TEST-001');
    $response->assertSee('1,500.00');
    $response->assertSee('ApexLogistics');
    $response->assertSee('Confirm & Post to Ledger');
    $response->assertSee('Edit & Submit');

    // Execute direct posting via DriveInvoicePostingService
    $move = app(DriveInvoicePostingService::class)->postClassification($classification, $user);

    $classification->refresh();
    expect($classification->validation_status)->toBe(DriveClassificationStatus::Posted)
        ->and($classification->created_invoice_id)->toBe($move->id);

    expect($move->state)->toBe(MoveState::POSTED)
        ->and($move->partner_id)->toBe($partner->id)
        ->and((float) $move->amount_total)->toBe(1500.00);
});
