<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Http\Requests\BillRequest;
use Webkul\Account\Http\Requests\InvoiceRequest;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Tax;
use Webkul\Purchase\Http\Requests\PurchaseOrderRequest;
use Webkul\Sale\Http\Requests\OrderRequest;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    $this->admin = AccountHelper::actingAsAdmin();

    $this->companyA = AccountHelper::company();

    $this->companyB = Company::factory()->create([
        'name'      => 'Company B Secondary',
        'is_active' => true,
    ]);

    // Active sale & purchase taxes for Company A
    $this->taxSaleA = Tax::factory()->create([
        'name'         => 'VAT 10% Sale A',
        'company_id'   => $this->companyA->id,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => true,
    ]);

    $this->taxPurchaseA = Tax::factory()->create([
        'name'         => 'VAT 10% Purchase A',
        'company_id'   => $this->companyA->id,
        'type_tax_use' => TypeTaxUse::PURCHASE,
        'is_active'    => true,
    ]);

    // Inactive sale & purchase taxes for Company A
    $this->taxInactiveSaleA = Tax::factory()->create([
        'name'         => 'Inactive Sale A',
        'company_id'   => $this->companyA->id,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => false,
    ]);

    $this->taxInactivePurchaseA = Tax::factory()->create([
        'name'         => 'Inactive Purchase A',
        'company_id'   => $this->companyA->id,
        'type_tax_use' => TypeTaxUse::PURCHASE,
        'is_active'    => false,
    ]);

    // Active sale & purchase taxes for Company B
    $this->taxSaleB = Tax::factory()->create([
        'name'         => 'VAT 15% Sale B',
        'company_id'   => $this->companyB->id,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => true,
    ]);

    $this->taxPurchaseB = Tax::factory()->create([
        'name'         => 'VAT 15% Purchase B',
        'company_id'   => $this->companyB->id,
        'type_tax_use' => TypeTaxUse::PURCHASE,
        'is_active'    => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| Test 7: Company Isolation Across Workflows
|--------------------------------------------------------------------------
*/

it('Test 7: isolates taxes by active company in scopeTaxQuery', function () {
    // Company A query for Sale taxes
    $saleTaxesA = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::SALE)->get();

    expect($saleTaxesA->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($saleTaxesA->pluck('id'))->not->toContain($this->taxSaleB->id)
        ->and($saleTaxesA->pluck('id'))->not->toContain($this->taxPurchaseA->id);

    // Company B query for Sale taxes
    $saleTaxesB = Tax::scopeTaxQuery(Tax::query(), $this->companyB->id, TypeTaxUse::SALE)->get();

    expect($saleTaxesB->pluck('id'))->toContain($this->taxSaleB->id)
        ->and($saleTaxesB->pluck('id'))->not->toContain($this->taxSaleA->id);

    // Missing/null company context never leaks taxes
    $emptyQuery = Tax::scopeTaxQuery(Tax::query(), null, TypeTaxUse::SALE)->get();
    expect($emptyQuery)->toBeEmpty();
});

it('Test 7: isolates purchase taxes by active company in scopeTaxQuery', function () {
    $purchaseTaxesA = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::PURCHASE)->get();

    expect($purchaseTaxesA->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($purchaseTaxesA->pluck('id'))->not->toContain($this->taxPurchaseB->id)
        ->and($purchaseTaxesA->pluck('id'))->not->toContain($this->taxSaleA->id);
});

/*
|--------------------------------------------------------------------------
| Test 8: Inactive Tax Filtering
|--------------------------------------------------------------------------
*/

it('Test 8: excludes inactive taxes from picker query', function () {
    $taxes = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::SALE)->get();

    expect($taxes->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($taxes->pluck('id'))->not->toContain($this->taxInactiveSaleA->id);

    $purchaseTaxes = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::PURCHASE)->get();

    expect($purchaseTaxes->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($purchaseTaxes->pluck('id'))->not->toContain($this->taxInactivePurchaseA->id);
});

/*
|--------------------------------------------------------------------------
| Server-Side Validation: Tampering, Company Switching, Deactivation
|--------------------------------------------------------------------------
*/

it('rejects manual tampering with cross-company tax ID in server-side validator', function () {
    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $path === '../../company_id' ? $this->companyA->id : null;
    $rule = $ruleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    // Submitting Tax B (Company B) under Company A context
    $rule('taxes', [$this->taxSaleB->id], $fail);

    expect($failedMessages)->not->toBeEmpty();
});

it('rejects manual tampering with inactive tax ID in server-side validator', function () {
    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $path === '../../company_id' ? $this->companyA->id : null;
    $rule = $ruleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    $rule('taxes', [$this->taxInactiveSaleA->id], $fail);

    expect($failedMessages)->not->toBeEmpty();
});

it('rejects company switching after tax selection', function () {
    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);

    // User is authorized for both Company A and Company B
    $this->admin->allowedCompanies()->syncWithoutDetaching([$this->companyB->id]);

    // Form company is switched to Company B before submit
    $getCallback = fn ($path) => $this->companyB->id;
    $rule = $ruleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    // Form still holds TaxSaleA from previous selection under Company A
    $rule('taxes', [$this->taxSaleA->id], $fail);

    expect($failedMessages)->not->toBeEmpty();
});

it('rejects tax deactivation that occurs before submission', function () {
    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $this->companyA->id;

    $transientTax = Tax::factory()->create([
        'name'         => 'Transient Tax',
        'company_id'   => $this->companyA->id,
        'type_tax_use' => TypeTaxUse::SALE,
        'is_active'    => true,
    ]);

    // Admin deactivates tax before form is submitted
    $transientTax->update(['is_active' => false]);

    $rule = $ruleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    $rule('taxes', [$transientTax->id], $fail);

    expect($failedMessages)->not->toBeEmpty();
});

it('rejects unauthorized company in tax validation rule', function () {
    // Create restricted user with default company A, no access to company B
    $restrictedUser = User::factory()->create([
        'default_company_id' => $this->companyA->id,
    ]);

    Auth::login($restrictedUser);

    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $this->companyB->id; // Trying to submit Company B
    $rule = $ruleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    $rule('taxes', [$this->taxSaleB->id], $fail);

    expect($failedMessages)->toContain('The selected company is not authorized.');
});

/*
|--------------------------------------------------------------------------
| Tax Usage Type Enforcement & Journal Entry Behavior
|--------------------------------------------------------------------------
*/

it('enforces tax usage type in validation rules', function () {
    $saleRuleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $this->companyA->id;
    $saleRule = $saleRuleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    // Submitting PURCHASE tax to SALE rule must fail
    $saleRule('taxes', [$this->taxPurchaseA->id], $fail);
    expect($failedMessages)->not->toBeEmpty();

    $purchaseRuleFactory = Tax::taxValidationRule(TypeTaxUse::PURCHASE);
    $purchaseRule = $purchaseRuleFactory($getCallback, null);

    $failedMessagesPurchase = [];
    $failPurchase = function ($msg) use (&$failedMessagesPurchase) {
        $failedMessagesPurchase[] = $msg;
    };

    // Submitting SALE tax to PURCHASE rule must fail
    $purchaseRule('taxes', [$this->taxSaleA->id], $failPurchase);
    expect($failedMessagesPurchase)->not->toBeEmpty();
});

it('preserves dual tax usage in Journal Entry while enforcing company and active isolation', function () {
    // Journal Entry allows both SALE and PURCHASE taxes (typeTaxUse = null)
    $journalTaxes = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, null)->get();

    expect($journalTaxes->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($journalTaxes->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($journalTaxes->pluck('id'))->not->toContain($this->taxSaleB->id)
        ->and($journalTaxes->pluck('id'))->not->toContain($this->taxPurchaseB->id)
        ->and($journalTaxes->pluck('id'))->not->toContain($this->taxInactiveSaleA->id)
        ->and($journalTaxes->pluck('id'))->not->toContain($this->taxInactivePurchaseA->id);

    // Server-side rule for Journal Entry accepts both Sale and Purchase taxes of Company A
    $journalRuleFactory = Tax::taxValidationRule(null);
    $getCallback = fn ($path) => $this->companyA->id;
    $journalRule = $journalRuleFactory($getCallback, null);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    $journalRule('taxes', [$this->taxSaleA->id, $this->taxPurchaseA->id], $fail);
    expect($failedMessages)->toBeEmpty();

    // But rejects cross-company taxes
    $journalRule('taxes', [$this->taxSaleB->id], $fail);
    expect($failedMessages)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Historical / Existing Document Preservation
|--------------------------------------------------------------------------
*/

it('preserves existing documents containing subsequently deactivated taxes on edit', function () {
    // Suppose an existing move line has taxInactiveSaleA attached historically
    $move = AccountHelper::invoice();
    $line = MoveLine::factory()->create([
        'move_id'    => $move->id,
        'company_id' => $this->companyA->id,
    ]);
    $line->taxes()->sync([$this->taxInactiveSaleA->id]);

    // Query for this existing record must include its existing inactive tax
    $query = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::SALE, [$this->taxInactiveSaleA->id]);
    $results = $query->get();

    expect($results->pluck('id'))->toContain($this->taxInactiveSaleA->id)
        ->and($results->pluck('id'))->toContain($this->taxSaleA->id);

    // Validation rule with existing record passed must succeed for its existing tax
    $ruleFactory = Tax::taxValidationRule(TypeTaxUse::SALE);
    $getCallback = fn ($path) => $this->companyA->id;
    $rule = $ruleFactory($getCallback, $line);

    $failedMessages = [];
    $fail = function ($msg) use (&$failedMessages) {
        $failedMessages[] = $msg;
    };

    $rule('taxes', [$this->taxInactiveSaleA->id], $fail);
    expect($failedMessages)->toBeEmpty();

    // But adding a DIFFERENT inactive tax that was not on the record must fail
    $rule('taxes', [$this->taxInactiveSaleA->id, $this->taxInactivePurchaseA->id], $fail);
    expect($failedMessages)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Form Request API Validation Hardening
|--------------------------------------------------------------------------
*/

it('hardens InvoiceRequest against cross-company and inactive taxes', function () {
    $request = new InvoiceRequest;
    $request->merge(['company_id' => $this->companyA->id]);

    $rules = $request->rules();
    $taxesRule = $rules['invoice_lines.*.taxes.*'];

    // Valid Sale Tax A
    $validatorPass = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxSaleA->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorPass->passes())->toBeTrue();

    // Cross-company Sale Tax B
    $validatorCrossCompany = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxSaleB->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorCrossCompany->fails())->toBeTrue();

    // Inactive Tax A
    $validatorInactive = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxInactiveSaleA->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorInactive->fails())->toBeTrue();

    // Purchase Tax A (wrong usage)
    $validatorWrongUsage = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxPurchaseA->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorWrongUsage->fails())->toBeTrue();
});

it('hardens BillRequest against cross-company and inactive taxes', function () {
    $request = new BillRequest;
    $request->merge(['company_id' => $this->companyA->id]);

    $rules = $request->rules();
    $taxesRule = $rules['invoice_lines.*.taxes.*'];

    // Valid Purchase Tax A
    $validatorPass = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxPurchaseA->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorPass->passes())->toBeTrue();

    // Sale Tax A (wrong usage for Bill)
    $validatorWrongUsage = Validator::make(
        ['invoice_lines' => [['taxes' => [$this->taxSaleA->id]]]],
        ['invoice_lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorWrongUsage->fails())->toBeTrue();
});

it('hardens OrderRequest against cross-company and inactive taxes', function () {
    $request = new OrderRequest;
    $request->merge(['company_id' => $this->companyA->id]);

    $rules = $request->rules();
    $taxesRule = $rules['lines.*.taxes.*'];

    // Valid Sale Tax A
    $validatorPass = Validator::make(
        ['lines' => [['taxes' => [$this->taxSaleA->id]]]],
        ['lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorPass->passes())->toBeTrue();

    // Cross-company Tax B
    $validatorCrossCompany = Validator::make(
        ['lines' => [['taxes' => [$this->taxSaleB->id]]]],
        ['lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorCrossCompany->fails())->toBeTrue();
});

it('hardens PurchaseOrderRequest against cross-company and inactive taxes', function () {
    $request = new PurchaseOrderRequest;
    $request->merge(['company_id' => $this->companyA->id]);

    $rules = $request->rules();
    $taxesRule = $rules['lines.*.taxes.*'];

    // Valid Purchase Tax A
    $validatorPass = Validator::make(
        ['lines' => [['taxes' => [$this->taxPurchaseA->id]]]],
        ['lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorPass->passes())->toBeTrue();

    // Cross-company Tax B
    $validatorCrossCompany = Validator::make(
        ['lines' => [['taxes' => [$this->taxPurchaseB->id]]]],
        ['lines.*.taxes.*' => $taxesRule],
    );
    expect($validatorCrossCompany->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Five Filament Resources Tax Pickers
|--------------------------------------------------------------------------
*/

it('verifies all five Filament resources use the hardened tax query and validation rule', function () {
    // 1. InvoiceResource
    $invoiceTaxQuery = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::SALE)->get();
    expect($invoiceTaxQuery->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($invoiceTaxQuery->pluck('id'))->not->toContain($this->taxSaleB->id)
        ->and($invoiceTaxQuery->pluck('id'))->not->toContain($this->taxPurchaseA->id)
        ->and($invoiceTaxQuery->pluck('id'))->not->toContain($this->taxInactiveSaleA->id);

    // 2. BillResource
    $billTaxQuery = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::PURCHASE)->get();
    expect($billTaxQuery->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($billTaxQuery->pluck('id'))->not->toContain($this->taxPurchaseB->id)
        ->and($billTaxQuery->pluck('id'))->not->toContain($this->taxSaleA->id)
        ->and($billTaxQuery->pluck('id'))->not->toContain($this->taxInactivePurchaseA->id);

    // 3. QuotationResource
    $quotationTaxQuery = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::SALE)->get();
    expect($quotationTaxQuery->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($quotationTaxQuery->pluck('id'))->not->toContain($this->taxSaleB->id)
        ->and($quotationTaxQuery->pluck('id'))->not->toContain($this->taxPurchaseA->id);

    // 4. OrderResource (Purchases)
    $purchaseOrderTaxQuery = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, TypeTaxUse::PURCHASE)->get();
    expect($purchaseOrderTaxQuery->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($purchaseOrderTaxQuery->pluck('id'))->not->toContain($this->taxPurchaseB->id)
        ->and($purchaseOrderTaxQuery->pluck('id'))->not->toContain($this->taxSaleA->id);

    // 5. JournalEntryResource
    $journalTaxQuery = Tax::scopeTaxQuery(Tax::query(), $this->companyA->id, null)->get();
    expect($journalTaxQuery->pluck('id'))->toContain($this->taxSaleA->id)
        ->and($journalTaxQuery->pluck('id'))->toContain($this->taxPurchaseA->id)
        ->and($journalTaxQuery->pluck('id'))->not->toContain($this->taxSaleB->id)
        ->and($journalTaxQuery->pluck('id'))->not->toContain($this->taxPurchaseB->id)
        ->and($journalTaxQuery->pluck('id'))->not->toContain($this->taxInactiveSaleA->id);
});
