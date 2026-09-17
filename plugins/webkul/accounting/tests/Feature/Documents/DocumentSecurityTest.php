<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Database\Factories\BankStatementFactory;
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
use Webkul\Accounting\Models\DocumentVersion;
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
});

// -- Mandatory change reason -------------------------------------------------

it('refuses to add a version without a change reason', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );

    expect(fn () => $this->service->addVersion(
        $this->user, $document,
        fakeUploadedFileWithRealContent('receipt-v2.pdf', 'application/pdf'),
        changeReason: '',
    ))->toThrow(RuntimeException::class, 'A reason is required');

    expect(fn () => $this->service->addVersion(
        $this->user, $document,
        fakeUploadedFileWithRealContent('receipt-v2.pdf', 'application/pdf'),
        changeReason: '   ',
    ))->toThrow(RuntimeException::class, 'A reason is required');

    expect($document->versions()->count())->toBe(1);
});

it('accepts a version replacement once a real change reason is given', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );

    $version = $this->service->addVersion(
        $this->user, $document,
        fakeUploadedFileWithRealContent('receipt-v2.pdf', 'application/pdf'),
        changeReason: 'Original scan was cut off',
    );

    expect($version->change_reason)->toBe('Original scan was cut off')
        ->and($document->versions()->count())->toBe(2);
});

// -- Immutable versions -------------------------------------------------------

it('refuses to update an existing document version through Eloquent', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Receipt, 'Fuel receipt', null,
        fakeUploadedFileWithRealContent('receipt.pdf', 'application/pdf'),
    );

    $version = $document->currentVersion;

    expect(fn () => $version->update(['change_reason' => 'Sneaking this in after the fact']))
        ->toThrow(RuntimeException::class, 'Document versions are immutable');

    expect(DocumentVersion::find($version->id)->change_reason)->toBeNull();
});

// -- Posted-record locking ----------------------------------------------------

if (! function_exists('makePostableInvoice')) {
    function makePostableInvoice(Company $company): Move
    {
        $currency = Currency::query()->firstOrFail();
        $income = Account::factory()->create(['account_type' => AccountType::INCOME, 'currency_id' => $currency->id]);
        $journal = Journal::factory()->sale()->create([
            'company_id'         => $company->id,
            'currency_id'        => $currency->id,
            'default_account_id' => $income->id,
        ]);
        $partner = Partner::factory()->create([
            'property_account_receivable_id' => Account::factory()->create(['account_type' => AccountType::ASSET_RECEIVABLE, 'currency_id' => $currency->id])->id,
            'property_account_payable_id'    => Account::factory()->create(['account_type' => AccountType::LIABILITY_PAYABLE, 'currency_id' => $currency->id])->id,
        ]);

        $invoice = Move::factory()->create([
            'move_type'   => MoveType::OUT_INVOICE,
            'company_id'  => $company->id,
            'currency_id' => $currency->id,
            'journal_id'  => $journal->id,
            'partner_id'  => $partner->id,
        ]);
        MoveLine::factory()->create([
            'move_id'      => $invoice->id,
            'display_type' => DisplayType::PRODUCT,
            'account_id'   => $income->id,
            'company_id'   => $company->id,
            'currency_id'  => $currency->id,
            'quantity'     => 1,
            'price_unit'   => 1000,
        ]);
        AccountFacade::computeAccountMove($invoice->refresh());

        return $invoice;
    }
}

it('allows detaching a document from an invoice that is still a draft', function () {
    $invoice = makePostableInvoice($this->company);
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Draft-stage note', null,
        fakeUploadedFileWithRealContent('note.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $invoice);

    $this->service->detach($this->user, $attachment);

    expect($invoice->documentAttachments()->count())->toBe(0);
});

it('refuses to detach a document from a posted invoice, but still allows attaching a new one', function () {
    $invoice = makePostableInvoice($this->company);
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Signed delivery note', null,
        fakeUploadedFileWithRealContent('note.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $invoice);

    $invoice->update(['state' => MoveState::POSTED]);

    expect(fn () => $this->service->detach($this->user, $attachment))
        ->toThrow(RuntimeException::class, 'posted record');

    expect($invoice->documentAttachments()->count())->toBe(1);

    $lastAudit = $document->audits()->first();
    expect($lastAudit->action->value)->toBe('access_denied')
        ->and($lastAudit->metadata['reason'])->toBe('posted_record_locked');

    // Adding MORE evidence to a posted record is still allowed -- only
    // removing an existing link is locked.
    $secondDocument = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Invoice, 'Follow-up correction note', null,
        fakeUploadedFileWithRealContent('correction.pdf', 'application/pdf'),
    );
    $this->service->attach($this->user, $secondDocument, $invoice);

    expect($invoice->documentAttachments()->count())->toBe(2);
});

it('refuses to detach a document from a completed bank statement', function () {
    $statement = BankStatementFactory::new()->accountingModule([
        'company_id'    => $this->company->id,
        'is_completed'  => true,
    ])->create();

    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::BankStatement, 'HBL statement export', null,
        fakeUploadedFileWithRealContent('statement.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $statement);

    expect(fn () => $this->service->detach($this->user, $attachment))
        ->toThrow(RuntimeException::class, 'posted record');

    expect($statement->documentAttachments()->count())->toBe(1);
});

it('allows detaching a document from a bank statement that is not yet completed', function () {
    $statement = BankStatementFactory::new()->accountingModule([
        'company_id'   => $this->company->id,
        'is_completed' => false,
    ])->create();

    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::BankStatement, 'Draft statement export', null,
        fakeUploadedFileWithRealContent('statement.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $statement);

    $this->service->detach($this->user, $attachment);

    expect($statement->documentAttachments()->count())->toBe(0);
});
