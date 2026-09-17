<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
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
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
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

    $this->service = app(DocumentService::class);

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
        'price_unit'   => 1000,
    ]);
    AccountFacade::computeAccountMove($this->invoice->refresh());
});

it('gets a real, queryable documentAttachments relation on Move -- the model the invoices plugin builds on', function () {
    expect($this->invoice->documentAttachments())->toBeInstanceOf(MorphMany::class);
    expect($this->invoice->documentAttachments)->toHaveCount(0);
});

it('attaches an uploaded document to a real invoice and finds it through the invoice\'s own relation', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Signed delivery note', null,
        fakeUploadedFileWithRealContent('delivery-note.pdf', 'application/pdf'),
    );

    $this->service->attach($this->user, $document, $this->invoice, note: 'Customer-signed proof of delivery');

    expect($this->invoice->documentAttachments()->count())->toBe(1);

    $attachment = $this->invoice->documentAttachments()->first();
    expect($attachment->document_id)->toBe($document->id)
        ->and($attachment->note)->toBe('Customer-signed proof of delivery')
        ->and($attachment->company_id)->toBe($this->company->id);
});

it('refuses to attach a document to an invoice belonging to a different company', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Wrong-company document', null,
        fakeUploadedFileWithRealContent('wrong.pdf', 'application/pdf'),
    );

    $otherInvoice = Move::factory()->create([
        'move_type'   => MoveType::OUT_INVOICE,
        'company_id'  => $otherCompany->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
    ]);

    expect(fn () => $this->service->attach($this->user, $document, $otherInvoice))
        ->toThrow(RuntimeException::class, 'belongs to a different company');

    expect($this->invoice->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->where('id', $document->id)->first()->attachments()->count())->toBe(0);
});

it('detaching from the invoice leaves the document itself intact', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Detach me', null,
        fakeUploadedFileWithRealContent('detach.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->invoice);

    $this->service->detach($this->user, $attachment);

    expect($this->invoice->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->find($document->id))->not->toBeNull();
});
