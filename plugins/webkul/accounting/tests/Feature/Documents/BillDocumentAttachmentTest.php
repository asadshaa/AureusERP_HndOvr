<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Bill;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

/**
 * Webkul\Accounting\Models\Bill is a thin subclass of the shared Move model
 * (same table as Invoice/JournalEntry) -- this is the class the accounting
 * plugin's own BillResource actually loads records as, so document
 * attachment coverage needs a real Bill instance, not a bare Move, to prove
 * Bill::resolveRelationUsing() (registered in AccountingServiceProvider) is
 * wired correctly. See InvoiceDocumentAttachmentTest for the same pattern
 * applied to the invoices plugin's own Invoice subclass.
 */
if (! function_exists('makeVendorBill')) {
    function makeVendorBill(Company $company): Bill
    {
        $currency = Currency::query()->firstOrFail();
        $expense = Account::factory()->create(['account_type' => AccountType::EXPENSE, 'currency_id' => $currency->id]);
        $journal = Journal::factory()->purchase()->create([
            'company_id'         => $company->id,
            'currency_id'        => $currency->id,
            'default_account_id' => $expense->id,
        ]);
        $partner = Partner::factory()->create([
            'property_account_receivable_id' => Account::factory()->create(['account_type' => AccountType::ASSET_RECEIVABLE, 'currency_id' => $currency->id])->id,
            'property_account_payable_id'    => Account::factory()->create(['account_type' => AccountType::LIABILITY_PAYABLE, 'currency_id' => $currency->id])->id,
        ]);

        $move = Move::factory()->create([
            'move_type'   => MoveType::IN_INVOICE,
            'company_id'  => $company->id,
            'currency_id' => $currency->id,
            'journal_id'  => $journal->id,
            'partner_id'  => $partner->id,
        ]);
        MoveLine::factory()->create([
            'move_id'      => $move->id,
            'display_type' => DisplayType::PRODUCT,
            'account_id'   => $expense->id,
            'company_id'   => $company->id,
            'currency_id'  => $currency->id,
            'quantity'     => 1,
            'price_unit'   => 500,
        ]);
        AccountFacade::computeAccountMove($move->refresh());

        // Re-fetch through Bill's own query so the returned instance is a
        // real Bill, exactly as BillResource's Eloquent query would load it
        // -- Move::factory() itself always returns a plain Move instance.
        return Bill::query()->findOrFail($move->id);
    }
}

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);
    $this->company = Company::factory()->create(['is_active' => true]);
    $this->user = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);
    $this->bill = makeVendorBill($this->company);
});

it('gets a real, queryable documentAttachments relation on Bill', function () {
    expect($this->bill->documentAttachments())->toBeInstanceOf(MorphMany::class);
    expect($this->bill->documentAttachments)->toHaveCount(0);
});

it('attaches an uploaded document to a real bill and finds it through the bill\'s own relation', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Bill, 'Supplier packing slip', null,
        fakeUploadedFileWithRealContent('packing-slip.pdf', 'application/pdf'),
    );

    $this->service->attach($this->user, $document, $this->bill, note: 'Matches PO-1029');

    expect($this->bill->documentAttachments()->count())->toBe(1);

    $attachment = $this->bill->documentAttachments()->first();
    expect($attachment->document_id)->toBe($document->id)
        ->and($attachment->note)->toBe('Matches PO-1029')
        ->and($attachment->company_id)->toBe($this->company->id);
});

it('refuses to attach a document to a bill belonging to a different company', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherBill = makeVendorBill($otherCompany);

    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Bill, 'Wrong-company document', null,
        fakeUploadedFileWithRealContent('wrong.pdf', 'application/pdf'),
    );

    expect(fn () => $this->service->attach($this->user, $document, $otherBill))
        ->toThrow(RuntimeException::class, 'belongs to a different company');

    expect($this->bill->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->where('id', $document->id)->first()->attachments()->count())->toBe(0);
});

it('detaching from the bill leaves the document itself intact', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Bill, 'Detach me', null,
        fakeUploadedFileWithRealContent('detach.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->bill);

    $this->service->detach($this->user, $attachment);

    expect($this->bill->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->find($document->id))->not->toBeNull();
});

it('refuses to detach a document from a posted bill, same as Phase 5 locking for invoices', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Bill, 'Signed goods receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->bill);

    $this->bill->update(['state' => MoveState::POSTED]);

    expect(fn () => $this->service->detach($this->user, $attachment))
        ->toThrow(RuntimeException::class, 'posted record');

    expect($this->bill->documentAttachments()->count())->toBe(1);
});
