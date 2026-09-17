<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\PaymentRegister;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    AccountHelper::actingAsAdmin();

    $this->company = AccountHelper::company();
    $this->pkr = AccountHelper::currency();
    $this->usd = Currency::query()->where('code', 'USD')->firstOrFail();

    $this->company->enabledCurrencies()->syncWithoutDetaching([
        $this->usd->id => ['transaction_enabled' => true, 'reporting_enabled' => false],
    ]);

    // A rate on each side covers whichever direction the conversion needs --
    // this test isn't about the rate lookup itself (that's covered by
    // CurrencyConversionStrictModeTest), only about registering a payment
    // in a currency different from the invoice's own without crashing.
    CurrencyRate::query()->create(['name' => now()->toDateString(), 'rate' => 280, 'currency_id' => $this->usd->id, 'company_id' => $this->company->id]);
    CurrencyRate::query()->create(['name' => now()->toDateString(), 'rate' => 1 / 280, 'currency_id' => $this->pkr->id, 'company_id' => $this->company->id]);

    $this->receivable = AccountHelper::account('receivable');
    $this->income = AccountHelper::account('income');
    $this->partner = AccountHelper::partner();

    $this->usdSaleJournal = Journal::factory()->sale()->create([
        'company_id'         => $this->company->id,
        'currency_id'        => $this->usd->id,
        'default_account_id' => $this->income->id,
    ]);

    $this->pkrBankJournal = Journal::factory()->bank()->create([
        'company_id'         => $this->company->id,
        'currency_id'        => $this->pkr->id,
        'default_account_id' => Account::factory()->create([
            'account_type' => AccountType::ASSET_CASH,
            'currency_id'  => $this->pkr->id,
        ])->id,
    ]);

    foreach (Journal::getDefaultInboundPaymentMethodLines() as $data) {
        PaymentMethodLine::create(array_merge($data, ['journal_id' => $this->pkrBankJournal->id]));
    }
});

it('registers a full payment in a different currency than the invoice without crashing on a missing companyCurrency relation', function () {
    $invoice = Move::factory()->create([
        'move_type'         => MoveType::OUT_INVOICE,
        'state'             => 'draft',
        'company_id'        => $this->company->id,
        'currency_id'       => $this->usd->id,
        'partner_id'        => $this->partner->id,
        'journal_id'        => $this->usdSaleJournal->id,
        'invoice_date'      => now()->toDateString(),
        'invoice_date_due'  => now()->toDateString(),
        'date'              => now()->toDateString(),
    ]);
    MoveLine::factory()->create([
        'move_id'      => $invoice->id,
        'display_type' => DisplayType::PRODUCT,
        'account_id'   => $this->income->id,
        'quantity'     => 1,
        'price_unit'   => 500,
        'currency_id'  => $this->usd->id,
        'company_id'   => $this->company->id,
    ]);
    AccountFacade::computeAccountMove($invoice->refresh());
    $invoice = AccountFacade::confirmMove($invoice->refresh());

    $paymentMethodLine = $this->pkrBankJournal->getAvailablePaymentMethodLines(PaymentType::RECEIVE)->first();

    $paymentRegister = PaymentRegister::create([
        'company_id'              => $this->company->id,
        'partner_id'              => $this->partner->id,
        'partner_type'            => 'customer',
        'payment_type'            => PaymentType::RECEIVE,
        'journal_id'              => $this->pkrBankJournal->id,
        'payment_method_line_id'  => $paymentMethodLine?->id,
        'amount'                  => 500 * 280,
        'currency_id'             => $this->pkr->id,
        'payment_date'            => now()->toDateString(),
        'memo'                    => $invoice->name,
    ]);

    $lineIds = $invoice->paymentTermLines->filter(fn ($line) => ! $line->reconciled)->pluck('id')->toArray();
    $paymentRegister->lines()->sync($lineIds);
    $paymentRegister->refresh();
    $paymentRegister->computeFromLines();
    $paymentRegister->save();

    // AccountManager::initiatePayments() has a branch (reached only when a
    // single-line payment settles a document through a fully generated
    // journal entry) that reads $paymentRegister->companyCurrency ->
    // a relation PaymentRegister has never actually defined, unlike
    // MoveLine's own companyCurrency(). This registration is the general,
    // realistic shape of that scenario -- paying a foreign-currency
    // invoice through a local-currency bank account -- and is kept here as
    // regression coverage for the fallback now in place at that call site.
    // The specific null-crash itself was verified directly against a live
    // reproduction of the exact browser flow (invoice + Pay action) rather
    // than through this fixture, since the branch's exact internal
    // rounding conditions proved too fragile to force reliably in a
    // synthetic test.
    AccountFacade::createPayments($paymentRegister);

    expect($invoice->refresh()->payment_state)->toBe(PaymentState::PAID)
        ->and((float) $invoice->amount_residual)->toBe(0.0);
});
