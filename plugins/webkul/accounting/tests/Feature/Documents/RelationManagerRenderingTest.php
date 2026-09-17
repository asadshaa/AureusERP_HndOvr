<?php

/**
 * A pure-Pest test of DocumentService never proves the "Supporting
 * documents" tab actually renders on a real resource page -- and it
 * didn't: during Phase 6 manual verification, the tab silently failed to
 * appear on every single owner type (Invoice, Bill, Journal Entry, Bank
 * Statement) for two independent reasons, neither of which any prior
 * test would have caught:
 *
 *  1. Filament v4 relation managers default to lazy-loading (only
 *     rendering once scrolled into view) -- fixed by disabling it on
 *     DocumentAttachmentsRelationManager.
 *  2. Several owner models have MULTIPLE separate Filament resources
 *     across different plugins/clusters, each needing its own
 *     resolveRelationUsing() registration (it is not inherited between
 *     sibling subclasses of Move). The accounting plugin's OWN Invoice/
 *     Bill resources (Accounting > Customers/Vendors) were missed
 *     entirely in the first pass -- only the invoices plugin's separate
 *     top-level Invoice/Bill resources were wired.
 *
 * These are deliberately lightweight smoke tests (mount + assert visible),
 * matching Phase 3's precedent of not testing a full upload interaction
 * through Livewire -- that's already covered, without any UI fragility,
 * by DocumentServiceTest and the per-owner attachment tests.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Database\Factories\BankStatementFactory;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages\ViewBankStatement;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\JournalEntryResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\JournalEntryResource\Pages\ViewJournalEntry;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource as AccountingInvoiceResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource\Pages\ViewInvoice as AccountingViewInvoice;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\PaymentResource as CustomerPaymentResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\PaymentResource\Pages\ViewPayment as ViewCustomerPayment;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\BillResource as AccountingBillResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\BillResource\Pages\ViewBill as AccountingViewBill;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\PaymentResource as VendorPaymentResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\PaymentResource\Pages\ViewPayment as ViewVendorPayment;
use Webkul\Accounting\Filament\RelationManagers\DocumentAttachmentsRelationManager;
use Webkul\Accounting\Models\Bill as AccountingBill;
use Webkul\Accounting\Models\Invoice as AccountingInvoice;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Models\Payment as AccountingPayment;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource as InvoicePluginInvoiceResource;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource\Pages\ViewInvoice as InvoicePluginViewInvoice;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\BillResource as InvoicePluginBillResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\BillResource\Pages\ViewBill as InvoicePluginViewBill;
use Webkul\Invoice\Models\Bill as InvoicePluginBill;
use Webkul\Invoice\Models\Invoice as InvoicePluginInvoice;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    Storage::fake('accounting_documents');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $this->company = Company::factory()->create(['is_active' => true]);
    $this->user = documentTestUser($this->company, [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::ManageDocuments,
    ]);
    test()->actingAs($this->user);
});

if (! function_exists('bareMoveFor')) {
    /**
     * Only rendering is under test here (not posting/balancing logic), so
     * a bare Move -- re-fetched as whatever concrete subclass each
     * resource actually uses -- is enough; no journal/partner/line setup.
     */
    function bareMoveFor(Company $company, MoveType $moveType): Move
    {
        return Move::factory()->create([
            'move_type'   => $moveType,
            'company_id'  => $company->id,
            'currency_id' => Currency::query()->firstOrFail()->id,
        ]);
    }
}

it('shows a working Supporting documents relation manager on the accounting plugin\'s own Invoice view page', function () {
    $invoice = AccountingInvoice::query()->findOrFail(bareMoveFor($this->company, MoveType::OUT_INVOICE)->id);

    expect(AccountingInvoiceResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($invoice, AccountingViewInvoice::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass'   => AccountingViewInvoice::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the accounting plugin\'s own Bill view page', function () {
    $bill = AccountingBill::query()->findOrFail(bareMoveFor($this->company, MoveType::IN_INVOICE)->id);

    expect(AccountingBillResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($bill, AccountingViewBill::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $bill,
        'pageClass'   => AccountingViewBill::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the invoices plugin\'s own Invoice view page', function () {
    $invoice = InvoicePluginInvoice::query()->findOrFail(bareMoveFor($this->company, MoveType::OUT_INVOICE)->id);

    expect(InvoicePluginInvoiceResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($invoice, InvoicePluginViewInvoice::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass'   => InvoicePluginViewInvoice::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the invoices plugin\'s own Bill view page', function () {
    $bill = InvoicePluginBill::query()->findOrFail(bareMoveFor($this->company, MoveType::IN_INVOICE)->id);

    expect(InvoicePluginBillResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($bill, InvoicePluginViewBill::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $bill,
        'pageClass'   => InvoicePluginViewBill::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the Journal Entry view page', function () {
    $entry = JournalEntry::query()->findOrFail(bareMoveFor($this->company, MoveType::ENTRY)->id);

    expect(JournalEntryResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($entry, ViewJournalEntry::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $entry,
        'pageClass'   => ViewJournalEntry::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the Customers > Payment view page', function () {
    // Found missing during the Google Drive integration inspection:
    // unlike Invoice/Bill/JournalEntry/BankStatement, neither Payment
    // resource had this tab at all -- Payment isn't a Move subclass, so
    // it never inherited the relation Move::resolveRelationUsing()
    // registers. Only 'company_id' and 'state' are NOT NULL on this
    // table; everything else genuinely is optional, so this stays a bare
    // fixture matching bareMoveFor()'s "rendering only" philosophy above.
    $payment = AccountingPayment::query()->create([
        'company_id' => $this->company->id,
        'state'      => 'draft',
        'name'       => 'PAY/2026/00001',
    ]);

    expect(CustomerPaymentResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($payment, ViewCustomerPayment::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $payment,
        'pageClass'   => ViewCustomerPayment::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the Vendors > Payment view page', function () {
    $payment = AccountingPayment::query()->create([
        'company_id' => $this->company->id,
        'state'      => 'draft',
        'name'       => 'PAY/2026/00002',
    ]);

    expect(VendorPaymentResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($payment, ViewVendorPayment::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $payment,
        'pageClass'   => ViewVendorPayment::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});

it('shows a working Supporting documents relation manager on the Bank Statement view page', function () {
    $statement = BankStatementFactory::new()->accountingModule(['company_id' => $this->company->id])->create();

    expect(BankStatementResource::getRelations())->toContain(DocumentAttachmentsRelationManager::class);
    expect(DocumentAttachmentsRelationManager::canViewForRecord($statement, ViewBankStatement::class))->toBeTrue();

    Livewire::test(DocumentAttachmentsRelationManager::class, [
        'ownerRecord' => $statement,
        'pageClass'   => ViewBankStatement::class,
    ])
        ->assertOk()
        ->assertSeeText('Upload document');
});
