<?php

/**
 * The accounts API only reads and changes records of the companies the token
 * user belongs to (CurrentCompany membership: default company plus allowed
 * companies). Another company's records are left out of lists and answer 404
 * on show, update, delete and the workflow actions; creating or updating a
 * record with another company's ids (journal, partner, tax...) answers 422.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Product;
use Webkul\Account\Models\Tax;
use Webkul\Account\Models\TaxGroup;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    $this->ownCompany = Company::factory()->create(['name' => 'API isolation ownCompany '.Str::random(8), 'is_active' => true]);
    $this->sisterCompany = Company::factory()->create(['name' => 'API isolation sisterCompany '.Str::random(8), 'is_active' => true]);
    $this->otherCompany = Company::factory()->create(['name' => 'API isolation otherCompany '.Str::random(8), 'is_active' => true]);
});

/** An API user whose active company is $company and who also belongs to $alsoIn (not a group owner). */
function actingAsCompanyApiUser(Company $company, array $alsoIn, array $permissions): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'default_company_id'  => $company->id,
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    $user->allowedCompanies()->sync(collect($alsoIn)->pluck('id')->all());
    CurrentCompany::flush();

    return $user;
}

/** @return array<int, string> */
function companyApiPermissions(string $suffix): array
{
    return ["view_any_{$suffix}", "view_{$suffix}", "update_{$suffix}", "delete_{$suffix}"];
}

/** @param  array<int, int>  $ids */
function companyApiListedIds(string $route, array $ids): array
{
    return collect(test()->getJson(route($route, ['filter[id]' => implode(',', $ids)]))->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

dataset('company scoped moves', [
    'invoices'     => ['invoices', MoveType::OUT_INVOICE, 'account_invoice'],
    'bills'        => ['bills', MoveType::IN_INVOICE, 'account_bill'],
    'credit notes' => ['credit-notes', MoveType::OUT_REFUND, 'account_refund'],
    'refunds'      => ['refunds', MoveType::IN_REFUND, 'account_refund'],
]);

it('lists only the moves of the companies the API user belongs to', function (string $resource, MoveType $type, string $permission) {
    actingAsCompanyApiUser($this->ownCompany, [$this->sisterCompany], companyApiPermissions($permission));

    $own = Move::factory()->create(['move_type' => $type, 'company_id' => $this->ownCompany->id]);
    $sister = Move::factory()->create(['move_type' => $type, 'company_id' => $this->sisterCompany->id]);
    $other = Move::factory()->create(['move_type' => $type, 'company_id' => $this->otherCompany->id]);

    expect(companyApiListedIds("admin.api.v1.accounts.{$resource}.index", [$own->id, $sister->id, $other->id]))
        ->toBe(collect([$own->id, $sister->id])->sort()->values()->all());

    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $own))->assertOk();
    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $sister))->assertOk();
})->with('company scoped moves');

it('answers 404 for another company\'s move and leaves it untouched', function (string $resource, MoveType $type, string $permission) {
    actingAsCompanyApiUser($this->ownCompany, [$this->sisterCompany], companyApiPermissions($permission));

    $other = Move::factory()->create(['move_type' => $type, 'company_id' => $this->otherCompany->id, 'reference' => 'untouched']);

    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $other))->assertNotFound();
    $this->putJson(route("admin.api.v1.accounts.{$resource}.update", $other), ['reference' => 'changed'])->assertNotFound();
    $this->postJson(route("admin.api.v1.accounts.{$resource}.cancel", $other))->assertNotFound();
    $this->postJson(route("admin.api.v1.accounts.{$resource}.confirm", $other))->assertNotFound();
    $this->deleteJson(route("admin.api.v1.accounts.{$resource}.destroy", $other))->assertNotFound();

    $other->refresh();

    expect($other->reference)->toBe('untouched')
        ->and($other->state)->toBe(MoveState::DRAFT)
        ->and(Move::query()->whereKey($other->id)->exists())->toBeTrue();
})->with('company scoped moves');

dataset('company scoped partners', [
    'customers' => ['customers', ['customer_rank' => 1]],
    'vendors'   => ['vendors', ['supplier_rank' => 1]],
]);

it('limits customers and vendors to the API user\'s companies', function (string $resource, array $rank) {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_partner'));

    $own = Partner::factory()->create([...$rank, 'company_id' => $this->ownCompany->id]);
    $other = Partner::factory()->create([...$rank, 'company_id' => $this->otherCompany->id, 'name' => 'Other Co Partner']);

    expect(companyApiListedIds("admin.api.v1.accounts.{$resource}.index", [$own->id, $other->id]))->toBe([$own->id]);

    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $own))->assertOk();
    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $other))->assertNotFound();
    $this->putJson(route("admin.api.v1.accounts.{$resource}.update", $other), ['name' => 'Renamed'])->assertNotFound();
    $this->deleteJson(route("admin.api.v1.accounts.{$resource}.destroy", $other))->assertNotFound();

    expect($other->refresh()->name)->toBe('Other Co Partner')
        ->and($other->trashed())->toBeFalse();
})->with('company scoped partners');

dataset('company scoped configuration', [
    'journals'         => ['journals', Journal::class, 'account_journal', false],
    'taxes'            => ['taxes', Tax::class, 'account_tax', false],
    'fiscal positions' => ['fiscal-positions', FiscalPosition::class, 'account_fiscal::position', false],
    'tax groups'       => ['tax-groups', TaxGroup::class, 'account_tax::group', true],
    'payment terms'    => ['payment-terms', PaymentTerm::class, 'account_payment::term', true],
]);

it('limits company accounting configuration to the API user\'s companies', function (string $resource, string $model, string $permission, bool $hasSharedRecords) {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions($permission));

    $own = $model::factory()->create(['company_id' => $this->ownCompany->id]);
    $other = $model::factory()->create(['company_id' => $this->otherCompany->id]);
    $ids = [$own->id, $other->id];
    $expected = [$own->id];

    if ($hasSharedRecords) {
        $shared = $model::factory()->create(['company_id' => null]);
        $ids[] = $shared->id;
        $expected[] = $shared->id;
    }

    expect(companyApiListedIds("admin.api.v1.accounts.{$resource}.index", $ids))->toBe(collect($expected)->sort()->values()->all());

    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $own))->assertOk();
    $this->getJson(route("admin.api.v1.accounts.{$resource}.show", $other))->assertNotFound();
    $this->deleteJson(route("admin.api.v1.accounts.{$resource}.destroy", $other))->assertNotFound();

    expect($model::query()->whereKey($other->id)->exists())->toBeTrue();
})->with('company scoped configuration');

it('limits a payment term\'s due terms to the API user\'s companies', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_payment::term'));

    $other = PaymentTerm::factory()->create(['company_id' => $this->otherCompany->id]);

    $this->getJson(route('admin.api.v1.accounts.payment-terms.due-terms.index', $other))->assertNotFound();
});

// ---------------------------------------------------------------------
// Writes: referenced ids must belong to the API user's companies
// ---------------------------------------------------------------------

/**
 * An invoice payload built from $company's records (a sale journal, a customer
 * and a shared product), so a test can swap in another company's ids.
 *
 * @return array<string, mixed>
 */
function companyApiInvoicePayload(Company $company): array
{
    $currency = Currency::query()->first() ?? Currency::factory()->create();
    $company->forceFill(['currency_id' => $currency->id])->saveQuietly();

    return [
        'partner_id'       => Partner::factory()->withAccounts()->create(['company_id' => $company->id])->id,
        'currency_id'      => $currency->id,
        'journal_id'       => Journal::factory()->sale()->create(['company_id' => $company->id, 'currency_id' => $currency->id])->id,
        'invoice_date'     => now()->toDateString(),
        'invoice_date_due' => now()->addDays(30)->toDateString(),
        'invoice_lines'    => [[
            'product_id' => Product::factory()->withAccounts()->create(['is_configurable' => false, 'company_id' => null])->id,
            'quantity'   => 1,
            'uom_id'     => UOM::factory()->create()->id,
            'price_unit' => 100,
        ]],
    ];
}

it('refuses an invoice that references another company\'s journal, customer, fiscal position, payment term, tax or product', function () {
    actingAsCompanyApiUser($this->ownCompany, [$this->sisterCompany], ['create_account_invoice']);

    $payload = companyApiInvoicePayload($this->ownCompany);
    $otherProduct = Product::factory()->withAccounts()->create(['is_configurable' => false, 'company_id' => $this->otherCompany->id]);
    $otherTax = Tax::factory()->create(['company_id' => $this->otherCompany->id, 'type_tax_use' => 'sale', 'is_active' => true]);
    $line = $payload['invoice_lines'][0];
    unset($payload['invoice_date_due']);

    $this->postJson(route('admin.api.v1.accounts.invoices.store'), [
        ...$payload,
        'partner_id'              => Partner::factory()->create(['company_id' => $this->otherCompany->id])->id,
        'journal_id'              => Journal::factory()->sale()->create(['company_id' => $this->otherCompany->id])->id,
        'fiscal_position_id'      => FiscalPosition::factory()->create(['company_id' => $this->otherCompany->id])->id,
        'invoice_payment_term_id' => PaymentTerm::factory()->create(['company_id' => $this->otherCompany->id])->id,
        'invoice_lines'           => [[...$line, 'product_id' => $otherProduct->id, 'taxes' => [$otherTax->id]]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['partner_id', 'journal_id', 'fiscal_position_id', 'invoice_payment_term_id', 'invoice_lines.0.product_id', 'invoice_lines.0.taxes.0']);

    // The journal must be the invoice's own company, not just one of the user's companies.
    $this->postJson(route('admin.api.v1.accounts.invoices.store'), [
        ...$payload,
        'invoice_date_due' => now()->addDays(30)->toDateString(),
        'journal_id'       => Journal::factory()->sale()->create(['company_id' => $this->sisterCompany->id])->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['journal_id']);

    expect(Move::query()->where('company_id', $this->otherCompany->id)->where('move_type', MoveType::OUT_INVOICE)->exists())->toBeFalse();
});

it('creates an invoice from the user\'s own journal and customer, a shared payment term and a shared product', function () {
    actingAsCompanyApiUser($this->ownCompany, [], ['create_account_invoice']);

    $payload = companyApiInvoicePayload($this->ownCompany);
    unset($payload['invoice_date_due']);
    $payload['invoice_payment_term_id'] = PaymentTerm::factory()->create(['company_id' => null])->id;

    $id = $this->postJson(route('admin.api.v1.accounts.invoices.store'), $payload)->assertCreated()->json('data.id');

    expect(Move::query()->findOrFail($id)->company_id)->toBe($this->ownCompany->id);
});

it('refuses to move a draft bill to another company\'s journal on update', function () {
    actingAsCompanyApiUser($this->ownCompany, [$this->sisterCompany], companyApiPermissions('account_bill'));

    $bill = Move::factory()->create(['move_type' => MoveType::IN_INVOICE, 'company_id' => $this->ownCompany->id]);
    $journalId = $bill->journal_id;

    foreach ([$this->otherCompany, $this->sisterCompany] as $company) {
        $this->patchJson(route('admin.api.v1.accounts.bills.update', $bill), [
            'journal_id' => Journal::factory()->purchase()->create(['company_id' => $company->id])->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_id']);
    }

    expect($bill->refresh()->journal_id)->toBe($journalId);
});

it('refuses to pay or reverse a move through another company\'s journal', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_invoice'));

    $invoice = Move::factory()->posted()->create(['move_type' => MoveType::OUT_INVOICE, 'company_id' => $this->ownCompany->id]);
    $otherJournal = Journal::factory()->bank()->create(['company_id' => $this->otherCompany->id]);

    $this->postJson(route('admin.api.v1.accounts.invoices.pay', $invoice), ['journal_id' => $otherJournal->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['journal_id']);

    $this->postJson(route('admin.api.v1.accounts.invoices.reverse', $invoice), ['journal_id' => $otherJournal->id, 'reason' => 'Wrong customer', 'date' => now()->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['journal_id']);

    // The invoice's own journal passes validation (the endpoint then checks the payment state).
    $this->postJson(route('admin.api.v1.accounts.invoices.pay', $invoice), ['journal_id' => Journal::factory()->bank()->create(['company_id' => $this->ownCompany->id])->id])
        ->assertJsonMissingValidationErrors(['journal_id']);

    expect(Move::query()->where('reversed_entry_id', $invoice->id)->exists())->toBeFalse();
});

dataset('company owned configuration writes', [
    'journals'         => ['journals', 'account_journal', ['name' => 'Isolation journal', 'code' => 'ISJ', 'type' => 'sale']],
    'tax groups'       => ['tax-groups', 'account_tax::group', ['name' => 'Isolation tax group']],
    'payment terms'    => ['payment-terms', 'account_payment::term', ['name' => 'Isolation term']],
    'fiscal positions' => ['fiscal-positions', 'account_fiscal::position', ['name' => 'Isolation position']],
]);

it('creates and updates company accounting configuration only in the user\'s companies', function (string $resource, string $permission, array $payload) {
    actingAsCompanyApiUser($this->ownCompany, [], ["create_{$permission}", "update_{$permission}", "view_{$permission}"]);

    $this->postJson(route("admin.api.v1.accounts.{$resource}.store"), [...$payload, 'company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    $id = $this->postJson(route("admin.api.v1.accounts.{$resource}.store"), [...$payload, 'company_id' => $this->ownCompany->id])
        ->assertCreated()
        ->json('data.id');

    $this->putJson(route("admin.api.v1.accounts.{$resource}.update", $id), ['company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);
})->with('company owned configuration writes');

it('gives a new journal or tax the active company, with that company\'s accounts and the user\'s or shared tax groups', function () {
    actingAsCompanyApiUser($this->ownCompany, [], ['create_account_journal', 'create_account_tax']);

    $ownAccount = Account::factory()->create();
    $ownAccount->companies()->sync([$this->ownCompany->id]);
    $otherAccount = Account::factory()->create();
    $otherAccount->companies()->sync([$this->otherCompany->id]);
    $journal = ['name' => 'Isolation misc', 'code' => 'ISM', 'type' => 'general'];

    $this->postJson(route('admin.api.v1.accounts.journals.store'), [...$journal, 'default_account_id' => $otherAccount->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['default_account_id']);

    $journalId = $this->postJson(route('admin.api.v1.accounts.journals.store'), [...$journal, 'default_account_id' => $ownAccount->id])
        ->assertCreated()
        ->json('data.id');

    expect(Journal::query()->findOrFail($journalId)->company_id)->toBe($this->ownCompany->id);

    $tax = [
        'name'                      => 'Isolation VAT',
        'type_tax_use'              => 'sale',
        'amount_type'               => 'percent',
        'amount'                    => 10,
        'invoice_repartition_lines' => [['repartition_type' => 'base'], ['repartition_type' => 'tax', 'factor_percent' => 100]],
        'refund_repartition_lines'  => [['repartition_type' => 'base'], ['repartition_type' => 'tax', 'factor_percent' => 100]],
    ];

    $this->postJson(route('admin.api.v1.accounts.taxes.store'), [...$tax, 'tax_group_id' => TaxGroup::factory()->create(['company_id' => $this->otherCompany->id])->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_group_id']);

    $taxId = $this->postJson(route('admin.api.v1.accounts.taxes.store'), [...$tax, 'tax_group_id' => TaxGroup::factory()->create(['company_id' => null])->id])
        ->assertCreated()
        ->json('data.id');

    expect(Tax::query()->findOrFail($taxId)->company_id)->toBe($this->ownCompany->id);
});

// ---------------------------------------------------------------------
// Chart of accounts (accounts are shared through accounts_account_companies)
// ---------------------------------------------------------------------

/** An account linked to the given companies. */
function companyApiAccount(array $companies, array $attributes = []): Account
{
    $account = Account::factory()->create($attributes);
    $account->companies()->sync(collect($companies)->pluck('id')->all());

    return $account;
}

it('refuses to delete an account with posted journal items and keeps the items', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_account'));

    $account = companyApiAccount([$this->ownCompany]);
    $move = Move::factory()->posted()->create(['company_id' => $this->ownCompany->id]);
    $line = MoveLine::factory()->create([
        'move_id'      => $move->id,
        'company_id'   => $this->ownCompany->id,
        'account_id'   => $account->id,
        'parent_state' => MoveState::POSTED,
    ]);

    $this->deleteJson(route('admin.api.v1.accounts.accounts.destroy', $account))
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', 'it has journal items');

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue()
        ->and(MoveLine::query()->whereKey($line->id)->value('account_id'))->toBe($account->id);

    // The database refuses it too: the journal items' foreign key restricts the delete.
    expect(fn () => $account->delete())->toThrow(QueryException::class);
});

it('refuses to delete an account that a journal points at', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_account'));

    $account = companyApiAccount([$this->ownCompany]);
    Journal::factory()->create(['company_id' => $this->ownCompany->id, 'default_account_id' => $account->id]);

    $this->deleteJson(route('admin.api.v1.accounts.accounts.destroy', $account))
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', 'it is used by journals');

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue();
});

it('deletes an unused account of the user\'s company and answers 404 for another company\'s account', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_account'));

    $unused = companyApiAccount([$this->ownCompany]);
    $other = companyApiAccount([$this->otherCompany]);

    $this->deleteJson(route('admin.api.v1.accounts.accounts.destroy', $other))->assertNotFound();
    $this->deleteJson(route('admin.api.v1.accounts.accounts.destroy', $unused))->assertOk();

    expect(Account::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('forbids changing or deleting an account shared with a company the API user is not in', function () {
    actingAsCompanyApiUser($this->ownCompany, [], companyApiPermissions('account_account'));

    $shared = companyApiAccount([$this->ownCompany, $this->otherCompany], ['name' => 'Shared chart account']);

    $this->getJson(route('admin.api.v1.accounts.accounts.show', $shared))->assertOk();

    $this->patchJson(route('admin.api.v1.accounts.accounts.update', $shared), ['name' => 'Renamed', 'deprecated' => true])
        ->assertForbidden();
    $this->deleteJson(route('admin.api.v1.accounts.accounts.destroy', $shared))->assertForbidden();

    $shared->refresh();

    expect($shared->name)->toBe('Shared chart account')
        ->and($shared->deprecated)->toBeFalse()
        ->and($shared->companies()->pluck('companies.id')->sort()->values()->all())
        ->toBe(collect([$this->ownCompany->id, $this->otherCompany->id])->sort()->values()->all());
});

it('lists and shows only the accounts linked to the API user\'s companies, with only their ledger lines', function () {
    actingAsCompanyApiUser($this->ownCompany, [$this->sisterCompany], companyApiPermissions('account_account'));

    $own = companyApiAccount([$this->ownCompany]);
    $sister = companyApiAccount([$this->sisterCompany]);
    $shared = companyApiAccount([$this->ownCompany, $this->otherCompany]);
    $other = companyApiAccount([$this->otherCompany]);
    $unlinked = Account::factory()->create();

    expect(companyApiListedIds('admin.api.v1.accounts.accounts.index', [$own->id, $sister->id, $shared->id, $other->id, $unlinked->id]))
        ->toBe(collect([$own->id, $sister->id, $shared->id])->sort()->values()->all());

    $this->getJson(route('admin.api.v1.accounts.accounts.show', $other))->assertNotFound();
    $this->getJson(route('admin.api.v1.accounts.accounts.show', $unlinked))->assertNotFound();
    $this->patchJson(route('admin.api.v1.accounts.accounts.update', $other), ['name' => 'Renamed'])->assertNotFound();

    $ownLine = MoveLine::factory()->create(['move_id' => Move::factory()->create(['company_id' => $this->ownCompany->id])->id, 'account_id' => $shared->id]);
    $otherLine = MoveLine::factory()->create(['move_id' => Move::factory()->create(['company_id' => $this->otherCompany->id])->id, 'account_id' => $shared->id]);

    $lineIds = collect($this->getJson(route('admin.api.v1.accounts.accounts.show', ['account' => $shared->id, 'include' => 'moveLines']))
        ->assertOk()
        ->json('data.moveLines'))->pluck('id')->all();

    expect($lineIds)->toBe([$ownLine->id])->not->toContain($otherLine->id);

    $indexLineIds = collect($this->getJson(route('admin.api.v1.accounts.accounts.index', ['filter[id]' => $shared->id, 'include' => 'moveLines']))
        ->assertOk()
        ->json('data.0.moveLines'))->pluck('id')->all();

    expect($indexLineIds)->toBe([$ownLine->id]);
});

it('creates an account in the active company and keeps codes unique per company', function () {
    actingAsCompanyApiUser($this->ownCompany, [], ['create_account_account', 'update_account_account', 'view_account_account']);

    companyApiAccount([$this->otherCompany], ['code' => 'ISO-CODE-1']);

    $id = $this->postJson(route('admin.api.v1.accounts.accounts.store'), ['name' => 'Own cash', 'code' => 'ISO-CODE-1', 'account_type' => 'asset_cash'])
        ->assertCreated()
        ->json('data.id');

    expect(Account::query()->findOrFail($id)->companies()->pluck('companies.id')->all())->toBe([$this->ownCompany->id]);

    $this->postJson(route('admin.api.v1.accounts.accounts.store'), ['name' => 'Duplicate', 'code' => 'ISO-CODE-1', 'account_type' => 'asset_cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $second = companyApiAccount([$this->ownCompany], ['code' => 'ISO-CODE-2']);

    $this->patchJson(route('admin.api.v1.accounts.accounts.update', $second), ['code' => 'ISO-CODE-1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->patchJson(route('admin.api.v1.accounts.accounts.update', $second), ['code' => 'ISO-CODE-2', 'name' => 'Same code kept'])
        ->assertOk();
});
