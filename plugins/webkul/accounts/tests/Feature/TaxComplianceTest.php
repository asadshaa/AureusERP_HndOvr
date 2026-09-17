<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    AccountHelper::actingAsAdmin();

    $this->income = AccountHelper::account('income');
    $this->partner = AccountHelper::partner();
});

/*
|--------------------------------------------------------------------------
| Test 5: Tax Disabled Company
|--------------------------------------------------------------------------
*/

it('Test 5: rejects posting a taxed invoice for a company that is not registered for sales tax', function () {
    $unregisteredCompany = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => false,
    ]);

    $tax = AccountHelper::taxWithAccounts(10);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, overrides: [
        'company_id' => $unregisteredCompany->id,
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100, taxes: [$tax]);

    AccountHelper::compute($invoice);

    expect(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.sales-tax-not-registered', ['company' => $unregisteredCompany->name]));

    expect($invoice->refresh()->state->value)->toBe('draft');
});

it('Test 5: allows posting the very same invoice once the tax is removed', function () {
    $unregisteredCompany = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => false,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, overrides: [
        'company_id' => $unregisteredCompany->id,
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100);

    AccountHelper::compute($invoice);

    $posted = AccountHelper::post($invoice);

    expect($posted->state->value)->toBe('posted');
});

/*
|--------------------------------------------------------------------------
| Test 6: Missing STRN
|--------------------------------------------------------------------------
*/

it('Test 6: rejects posting a taxed invoice for a company that is registered but has no STRN on file', function () {
    $registeredNoStrn = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => true,
        'strn'                     => null,
    ]);

    $tax = AccountHelper::taxWithAccounts(10);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, overrides: [
        'company_id' => $registeredNoStrn->id,
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100, taxes: [$tax]);

    AccountHelper::compute($invoice);

    expect(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.strn-required', ['company' => $registeredNoStrn->name]));

    expect($invoice->refresh()->state->value)->toBe('draft');
});

it('Test 6: posts a taxed invoice once the registered company has an STRN on file', function () {
    $registeredWithStrn = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => true,
        'strn'                     => 'STRN-000123',
    ]);

    $tax = AccountHelper::taxWithAccounts(10);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, overrides: [
        'company_id' => $registeredWithStrn->id,
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100, taxes: [$tax]);

    AccountHelper::compute($invoice);

    $posted = AccountHelper::post($invoice);

    expect($posted->state->value)->toBe('posted')
        ->and((float) $posted->amount_tax)->toBe(10.0);
});

/*
|--------------------------------------------------------------------------
| Scope of the check: sale-side documents only, and only when a line
| actually carries a tax
|--------------------------------------------------------------------------
*/

it('does not block an untaxed invoice for an unregistered company', function () {
    $unregisteredCompany = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => false,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, overrides: [
        'company_id' => $unregisteredCompany->id,
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100);

    AccountHelper::compute($invoice);

    expect(AccountHelper::post($invoice)->state->value)->toBe('posted');
});

it('does not block a taxed vendor bill regardless of the company\'s own sales-tax registration', function () {
    $unregisteredCompany = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'is_active'                => true,
        'is_sales_tax_registered'  => false,
    ]);

    $expense = AccountHelper::account('expense');
    $tax = AccountHelper::taxWithAccounts(10, type: TypeTaxUse::PURCHASE);

    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, $this->partner, overrides: [
        'company_id' => $unregisteredCompany->id,
    ]);
    AccountHelper::productLine($bill, $expense, qty: 1, priceUnit: 100, taxes: [$tax]);

    AccountHelper::compute($bill);

    // A vendor bill's tax is the vendor's own registration to answer for,
    // not this (purchasing) company's -- so it must never be blocked by
    // this company's sales-tax registration status.
    expect(AccountHelper::post($bill)->state->value)->toBe('posted');
});

/*
|--------------------------------------------------------------------------
| Company & Partner tax identity fields exist and behave as configured
|--------------------------------------------------------------------------
*/

it('defaults a brand new company to not registered for sales tax', function () {
    $company = Company::factory()->create(['currency_id' => AccountHelper::currency()->id])->refresh();

    expect($company->is_sales_tax_registered)->toBeFalse()
        ->and($company->strn)->toBeNull();
});

it('persists a company\'s STRN and registration flag independently of its NTN', function () {
    $company = Company::factory()->create([
        'currency_id'              => AccountHelper::currency()->id,
        'tax_id'                   => 'NTN-999',
        'is_sales_tax_registered'  => true,
        'strn'                     => 'STRN-000123',
    ]);

    $company->refresh();

    expect($company->tax_id)->toBe('NTN-999')
        ->and($company->is_sales_tax_registered)->toBeTrue()
        ->and($company->strn)->toBe('STRN-000123');
});

it('persists a partner\'s STRN and registration flag the same way', function () {
    $partner = AccountHelper::partner();
    $partner->update([
        'is_sales_tax_registered' => true,
        'strn'                    => 'STRN-CUSTOMER-1',
    ]);

    expect($partner->refresh()->is_sales_tax_registered)->toBeTrue()
        ->and($partner->strn)->toBe('STRN-CUSTOMER-1');
});
