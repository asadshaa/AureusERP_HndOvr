<?php

/**
 * The accounts API only reads and changes records of the companies the token
 * user belongs to (CurrentCompany membership: default company plus allowed
 * companies). Another company's records are left out of lists and answer 404
 * on show, update, delete and the workflow actions.
 */

use Illuminate\Support\Str;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Tax;
use Webkul\Account\Models\TaxGroup;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

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
